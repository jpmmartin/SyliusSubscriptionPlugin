<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Factory;

use JpmMartin\SyliusSubscriptionPlugin\Consent\SubscriptionConsentRecorderInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use JpmMartin\SyliusSubscriptionPlugin\OrderProcessing\SubscriptionPlanPriceProcessor;
use Sylius\Component\Core\Calculator\ProductVariantPricesCalculatorInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Webmozart\Assert\Assert;

/**
 * Each item keeps what its line renewed on: its plan, or else the frequency its cart was repeated with.
 * The frozen price is the line's unit price, the variant's price less that discount, before the
 * order's promotions: a promotion on the first order does not carry over to the renewals.
 *
 * A line on terms with an introductory price carries that price instead. Its item then freezes the
 * normal price, worked out as the cart does, and keeps the introductory one with the cycles it lasts.
 * A line given a free trial costs nothing: its item freezes the normal price too, and the subscription,
 * whose lines all had the same trial, keeps its days. A prepaid line costs its block of deliveries: its
 * item freezes the price of one, and the subscription is billed that many intervals at a time.
 */
final class SubscriptionFactory implements SubscriptionFactoryInterface
{
    /**
     * @param FactoryInterface<object> $decorated
     * @param FactoryInterface<SubscriptionItemInterface> $itemFactory
     */
    public function __construct(
        private readonly FactoryInterface $decorated,
        private readonly FactoryInterface $itemFactory,
        private readonly SubscriptionConsentRecorderInterface $consentRecorder,
        private readonly ProductVariantPricesCalculatorInterface $productVariantPricesCalculator,
    ) {
    }

    public function createNew(): SubscriptionInterface
    {
        $subscription = $this->decorated->createNew();
        Assert::isInstanceOf($subscription, SubscriptionInterface::class);

        return $subscription;
    }

    public function createFromOrderItems(array $orderItems): SubscriptionInterface
    {
        Assert::notEmpty($orderItems, 'A subscription starts from at least one line.');
        $order = $orderItems[0]->getOrder();
        Assert::isInstanceOf($order, OrderInterface::class);
        $customer = $order->getCustomer();
        Assert::isInstanceOf($customer, CustomerInterface::class, 'A subscription needs the customer of its order.');
        $paymentMethod = $order->getLastPayment()?->getMethod();
        Assert::isInstanceOf($paymentMethod, PaymentMethodInterface::class, 'A subscription needs the payment method of its order.');

        $subscription = $this->createNew();
        $subscription->setCustomer($customer);
        $subscription->setChannel($order->getChannel());
        $subscription->setCurrencyCode($order->getCurrencyCode());
        $subscription->setPaymentMethod($paymentMethod);

        $shippingRequired = false;
        foreach ($orderItems as $orderItem) {
            Assert::same($orderItem->getOrder(), $order, 'A subscription starts from the lines of one order.');
            $item = $this->createItem($orderItem, $order->getChannel());
            $terms = $item->getTerms();
            Assert::notNull($terms);
            if ($subscription->getItems()->isEmpty()) {
                // A prepaid block is charged once for its deliveries: billed that many intervals at a time.
                $subscription->setBillingIntervalCount($terms->getIntervalCount() * $terms->getDeliveriesPerCharge());
                $subscription->setBillingIntervalUnit($terms->getIntervalUnit());
                $subscription->setDeliveryIntervalCount($terms->getIntervalCount());
                $subscription->setDeliveryIntervalUnit($terms->getIntervalUnit());
            }
            Assert::true(
                $terms->getIntervalCount() === $subscription->getDeliveryIntervalCount() && $terms->getIntervalUnit() === $subscription->getDeliveryIntervalUnit(),
                'The items of a subscription share its interval.',
            );
            Assert::same($terms->getDeliveriesPerCharge(), $subscription->getDeliveriesPerCharge(), 'The items of a subscription share its deliveries per charge.');
            Assert::isInstanceOf($orderItem, SubscriptionPlanAwareInterface::class);
            if ($subscription->getItems()->isEmpty()) {
                $subscription->setTrialDays($orderItem->getSubscriptionTrialDays());
            }
            Assert::same($orderItem->getSubscriptionTrialDays(), $subscription->getTrialDays(), 'The items of a subscription share its free trial.');
            $subscription->addItem($item);
            $shippingRequired = $shippingRequired || true === $orderItem->getVariant()?->isShippingRequired();
        }

        if ($shippingRequired) {
            $shipment = $order->getShipments()->first();
            $shippingMethod = $shipment instanceof ShipmentInterface ? $shipment->getMethod() : null;
            $subscription->setShippingMethod($shippingMethod instanceof ShippingMethodInterface ? $shippingMethod : null);
        }

        $consent = $this->consentRecorder->findFor($order);
        if (null !== $consent) {
            $subscription->setConsentVersion($consent->getTextVersion());
            $subscription->setConsentText($consent->getText());
            $subscription->setConsentAcceptedAt($consent->getAcceptedAt());
        }

        return $subscription;
    }

    private function createItem(OrderItemInterface $orderItem, ?ChannelInterface $channel): SubscriptionItemInterface
    {
        Assert::isInstanceOf($orderItem, SubscriptionPlanAwareInterface::class);
        $terms = $orderItem->getSubscriptionTerms();
        Assert::notNull($terms, 'Only a line with a plan or a frequency starts a subscription.');
        $variant = $orderItem->getVariant();
        Assert::notNull($variant);

        $item = $this->itemFactory->createNew();
        Assert::isInstanceOf($item, SubscriptionItemInterface::class);
        $item->setProductVariant($variant);
        $item->setQuantity($orderItem->getQuantity());
        $hasTrial = null !== $orderItem->getSubscriptionTrialDays();
        // A prepaid line costs its whole block; the item freezes the price of one delivery.
        if (null === $terms->getIntroductoryDiscountPercentage() && !$hasTrial && 1 === $terms->getDeliveriesPerCharge()) {
            $item->setUnitPrice($orderItem->getUnitPrice());
        } else {
            Assert::notNull($channel);
            $price = $this->productVariantPricesCalculator->calculate($variant, ['channel' => $channel]);
            $item->setUnitPrice(SubscriptionPlanPriceProcessor::applyDiscount($price, $terms->getDiscountPercentage()));
            if (!$hasTrial && null !== $terms->getIntroductoryDiscountPercentage()) {
                $item->setIntroductoryPrice($orderItem->getUnitPrice(), $terms->getIntroductoryCycles());
            }
        }
        $item->setCommitmentCycles($terms->getCommitmentCycles());
        $item->setPlan($orderItem->getSubscriptionPlan());
        $item->setFrequency(null === $orderItem->getSubscriptionPlan() ? $orderItem->getSubscriptionFrequency() : null);
        $item->setOriginOrderItem($orderItem);

        return $item;
    }
}
