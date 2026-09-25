<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Management;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\EventPublisher;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionAddressChanged;
use JpmMartin\SyliusSubscriptionPlugin\Order\AddressCopier;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\OrderItemUnitInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Shipping\Calculator\DelegatingCalculatorInterface;
use Sylius\Component\Shipping\Resolver\ShippingMethodsResolverInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Webmozart\Assert\Assert;

/**
 * The shipping methods are the checkout's: Sylius's resolver and calculator are asked about an order
 * that is only built in memory, with the subscription's renewing items at their frozen prices, its
 * channel and the new address. That order is never persisted, and nothing stored refers to it.
 */
final class SubscriptionAddressChanger implements SubscriptionAddressChangerInterface
{
    private const FINAL_STATES = [SubscriptionInterface::STATE_CANCELLED, SubscriptionInterface::STATE_COMPLETED];

    /**
     * @param FactoryInterface<OrderInterface> $orderFactory
     * @param FactoryInterface<OrderItemInterface> $orderItemFactory
     * @param FactoryInterface<ShipmentInterface> $shipmentFactory
     */
    public function __construct(
        private readonly FactoryInterface $orderFactory,
        private readonly FactoryInterface $orderItemFactory,
        private readonly OrderItemQuantityModifierInterface $quantityModifier,
        private readonly FactoryInterface $shipmentFactory,
        private readonly ShippingMethodsResolverInterface $shippingMethodsResolver,
        private readonly DelegatingCalculatorInterface $shippingCalculator,
        private readonly AddressCopier $addressCopier,
        private readonly EventPublisher $eventPublisher,
    ) {
    }

    public function canChange(SubscriptionInterface $subscription): bool
    {
        return !\in_array($subscription->getState(), self::FINAL_STATES, true);
    }

    public function requiresShipping(SubscriptionInterface $subscription): bool
    {
        foreach ($subscription->getItems() as $item) {
            if ($item->isRenewable() && true === $item->getProductVariant()?->isShippingRequired()) {
                return true;
            }
        }

        return false;
    }

    public function shippingMethodsFor(SubscriptionInterface $subscription, AddressInterface $shippingAddress): array
    {
        $shipment = $this->shipmentOfARenewalTo($subscription, $shippingAddress);

        $offers = [];
        foreach ($this->shippingMethodsResolver->getSupportedMethods($shipment) as $method) {
            if (!$method instanceof ShippingMethodInterface) {
                continue;
            }
            $shipment->setMethod($method);
            $offers[] = new ShippingMethodOffer($method, $this->shippingCalculator->calculate($shipment));
        }
        usort($offers, static fn (ShippingMethodOffer $a, ShippingMethodOffer $b): int => $a->cost <=> $b->cost);

        return $offers;
    }

    public function change(
        SubscriptionInterface $subscription,
        AddressInterface $shippingAddress,
        ?AddressInterface $billingAddress = null,
        ?ShippingMethodInterface $shippingMethod = null,
    ): void {
        Assert::true($this->canChange($subscription), 'The addresses of a cancelled or completed subscription cannot be changed.');

        $shippingMethodChanged = false;
        if ($this->requiresShipping($subscription)) {
            $reaching = array_map(static fn (ShippingMethodOffer $offer): ShippingMethodInterface => $offer->method, $this->shippingMethodsFor($subscription, $shippingAddress));
            Assert::notEmpty($reaching, 'No shipping method reaches this address.');

            $chosen = $shippingMethod ?? $subscription->getShippingMethod();
            Assert::notNull($chosen, 'A shipping method that reaches this address has to be chosen.');
            Assert::true(\in_array($chosen, $reaching, true), 'The shipping method does not reach this address.');

            $shippingMethodChanged = $chosen !== $subscription->getShippingMethod();
            $subscription->setShippingMethod($chosen);
        }

        $subscription->setShippingAddress($this->addressCopier->copy($shippingAddress));
        $subscription->setBillingAddress($this->addressCopier->copy($billingAddress ?? $shippingAddress));

        // Publishing never stops a change: a subscription not stored yet has no identifier to carry.
        $subscriptionId = $subscription->getId();
        if (null !== $subscriptionId) {
            $this->eventPublisher->publish(new SubscriptionAddressChanged($subscriptionId, $shippingMethodChanged));
        }
    }

    /** The shipment of an order built in memory, as the checkout builds one: its units, of what is shipped. */
    private function shipmentOfARenewalTo(SubscriptionInterface $subscription, AddressInterface $shippingAddress): ShipmentInterface
    {
        $order = $this->orderFactory->createNew();
        $order->setChannel($subscription->getChannel());
        $order->setCustomer($subscription->getCustomer());
        $order->setCurrencyCode($subscription->getCurrencyCode());
        $order->setShippingAddress($this->addressCopier->copy($shippingAddress));

        $shipment = $this->shipmentFactory->createNew();
        $shipment->setOrder($order);
        foreach ($subscription->getItems() as $item) {
            if (!$item->isRenewable()) {
                continue;
            }
            $line = $this->orderItemFactory->createNew();
            $line->setVariant($item->getProductVariant());
            $line->setUnitPrice($item->getUnitPrice());
            $this->quantityModifier->modify($line, $item->getQuantity());
            $order->addItem($line);

            if (true !== $item->getProductVariant()?->isShippingRequired()) {
                continue;
            }
            foreach ($line->getUnits() as $unit) {
                Assert::isInstanceOf($unit, OrderItemUnitInterface::class);
                $shipment->addUnit($unit);
            }
        }
        $order->addShipment($shipment);

        return $shipment;
    }
}
