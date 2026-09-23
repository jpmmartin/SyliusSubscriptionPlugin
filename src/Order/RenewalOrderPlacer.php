<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Order;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Webmozart\Assert\Assert;

/**
 * The order goes through the same checkout transitions as the shop's, so Sylius works out its
 * shipments, charges and payment, and skips shipping when no line needs it. The lines are immutable:
 * recalculating the order never changes their price. They are added to the order as they are, not
 * through the order modifier, which would merge two items of the same variant into one line.
 *
 * Stock is checked for what the order takes of each variant altogether, so two items of the same
 * variant never take more than there is.
 */
final class RenewalOrderPlacer implements RenewalOrderPlacerInterface
{
    /**
     * @param FactoryInterface<OrderInterface> $orderFactory
     * @param FactoryInterface<OrderItemInterface> $orderItemFactory
     * @param FactoryInterface<AddressInterface> $addressFactory
     * @param FactoryInterface<SubscriptionCycleItemInterface> $cycleItemFactory
     */
    public function __construct(
        private readonly FactoryInterface $orderFactory,
        private readonly FactoryInterface $orderItemFactory,
        private readonly FactoryInterface $addressFactory,
        private readonly FactoryInterface $cycleItemFactory,
        private readonly OrderItemQuantityModifierInterface $quantityModifier,
        private readonly AvailabilityCheckerInterface $availabilityChecker,
        private readonly StateMachineInterface $stateMachine,
        private readonly ObjectManager $orderManager,
    ) {
    }

    public function place(SubscriptionCycleInterface $cycle): ?OrderInterface
    {
        $subscription = $cycle->getSubscription();
        Assert::notNull($subscription);

        $taken = $this->recordItems($cycle, $subscription);
        if ([] === $taken) {
            return null;
        }

        $lastOrder = $this->lastOrderOf($subscription);
        Assert::notNull($lastOrder, 'A subscription renews with the addresses of its last order.');

        $order = $this->orderFactory->createNew();
        $order->setChannel($subscription->getChannel());
        $order->setCustomer($subscription->getCustomer());
        $order->setCurrencyCode($subscription->getCurrencyCode());
        $order->setLocaleCode($lastOrder->getLocaleCode());
        $order->setShippingAddress($this->copyOf($lastOrder->getShippingAddress()));
        $order->setBillingAddress($this->copyOf($lastOrder->getBillingAddress()));

        foreach ($taken as $item) {
            $line = $this->orderItemFactory->createNew();
            $line->setVariant($item->getProductVariant());
            $line->setUnitPrice($item->getUnitPrice());
            $line->setImmutable(true);
            $this->quantityModifier->modify($line, $item->getQuantity());
            $order->addItem($line);
        }
        $this->orderManager->persist($order);

        // Sylius processes the order on each of these steps.
        $this->applyCheckout($order, OrderCheckoutTransitions::TRANSITION_ADDRESS);

        if ($this->canApplyCheckout($order, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING)) {
            foreach ($order->getShipments() as $shipment) {
                $shipment->setMethod($subscription->getShippingMethod() ?? $shipment->getMethod());
            }
            $this->applyCheckout($order, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING);
        }

        if ($this->canApplyCheckout($order, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT)) {
            $order->getLastPayment(PaymentInterface::STATE_CART)?->setMethod($subscription->getPaymentMethod());
            $this->applyCheckout($order, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT);
        }

        // Linked, and stored, before its checkout completes: an order a cycle points at starts no subscription.
        $cycle->setOrder($order);
        $this->orderManager->flush();

        $this->applyCheckout($order, OrderCheckoutTransitions::TRANSITION_COMPLETE);

        return $order;
    }

    /** @return list<SubscriptionItemInterface> the items the order takes, in the subscription's order */
    private function recordItems(SubscriptionCycleInterface $cycle, SubscriptionInterface $subscription): array
    {
        foreach ($cycle->getItems()->toArray() as $recorded) {
            $cycle->removeItem($recorded);
        }

        $channel = $subscription->getChannel();
        Assert::isInstanceOf($channel, ChannelInterface::class);

        $taken = [];
        /** @var array<int, int> $quantityTaken by variant */
        $quantityTaken = [];
        foreach ($subscription->getItems() as $item) {
            if (!$item->isRenewable()) {
                continue;
            }

            $variant = $item->getProductVariant();
            Assert::notNull($variant);
            $variantKey = spl_object_id($variant);
            $quantity = ($quantityTaken[$variantKey] ?? 0) + $item->getQuantity();
            $skippedReason = $this->whyUnavailable($variant, $channel, $quantity);

            $cycleItem = $this->cycleItemFactory->createNew();
            Assert::isInstanceOf($cycleItem, SubscriptionCycleItemInterface::class);
            $cycleItem->setSubscriptionItem($item);
            $cycleItem->setQuantity($item->getQuantity());
            $cycleItem->setUnitPrice($item->getUnitPrice());
            $cycleItem->setSkippedReason($skippedReason);
            $cycle->addItem($cycleItem);

            if (null === $skippedReason) {
                $quantityTaken[$variantKey] = $quantity;
                $taken[] = $item;
            }
        }

        return $taken;
    }

    private function whyUnavailable(ProductVariantInterface $variant, ChannelInterface $channel, int $quantity): ?string
    {
        $product = $variant->getProduct();
        Assert::isInstanceOf($product, ProductInterface::class);

        if (!$variant->isEnabled() || !$product->isEnabled()) {
            return SubscriptionCycleItemInterface::SKIPPED_DISABLED;
        }

        if (!$product->hasChannel($channel)) {
            return SubscriptionCycleItemInterface::SKIPPED_NOT_IN_CHANNEL;
        }

        if (!$this->availabilityChecker->isStockSufficient($variant, $quantity)) {
            return SubscriptionCycleItemInterface::SKIPPED_OUT_OF_STOCK;
        }

        return null;
    }

    private function lastOrderOf(SubscriptionInterface $subscription): ?OrderInterface
    {
        $lastOrder = null;
        $lastNumber = 0;
        foreach ($subscription->getCycles() as $cycle) {
            if (null !== $cycle->getOrder() && $cycle->getNumber() > $lastNumber) {
                $lastOrder = $cycle->getOrder();
                $lastNumber = $cycle->getNumber();
            }
        }

        return $lastOrder;
    }

    /** A new address with the same details: an address belongs to one order only. */
    private function copyOf(?AddressInterface $address): ?AddressInterface
    {
        if (null === $address) {
            return null;
        }

        $copy = $this->addressFactory->createNew();
        $copy->setFirstName($address->getFirstName());
        $copy->setLastName($address->getLastName());
        $copy->setPhoneNumber($address->getPhoneNumber());
        $copy->setCompany($address->getCompany());
        $copy->setStreet($address->getStreet());
        $copy->setCity($address->getCity());
        $copy->setPostcode($address->getPostcode());
        $copy->setCountryCode($address->getCountryCode());
        $copy->setProvinceCode($address->getProvinceCode());
        $copy->setProvinceName($address->getProvinceName());

        return $copy;
    }

    private function canApplyCheckout(OrderInterface $order, string $transition): bool
    {
        return $this->stateMachine->can($order, OrderCheckoutTransitions::GRAPH, $transition);
    }

    private function applyCheckout(OrderInterface $order, string $transition): void
    {
        $this->stateMachine->apply($order, OrderCheckoutTransitions::GRAPH, $transition);
    }
}
