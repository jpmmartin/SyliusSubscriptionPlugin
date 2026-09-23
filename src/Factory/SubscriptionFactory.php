<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Factory;

use JpmMartin\SyliusSubscriptionPlugin\Consent\SubscriptionConsentRecorderInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
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
            $item = $this->createItem($orderItem);
            $terms = $item->getTerms();
            Assert::notNull($terms);
            if ($subscription->getItems()->isEmpty()) {
                $subscription->setBillingIntervalCount($terms->getIntervalCount());
                $subscription->setBillingIntervalUnit($terms->getIntervalUnit());
                $subscription->setDeliveryIntervalCount($terms->getIntervalCount());
                $subscription->setDeliveryIntervalUnit($terms->getIntervalUnit());
            }
            Assert::true(
                $terms->getIntervalCount() === $subscription->getBillingIntervalCount() && $terms->getIntervalUnit() === $subscription->getBillingIntervalUnit(),
                'The items of a subscription share its interval.',
            );
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

    private function createItem(OrderItemInterface $orderItem): SubscriptionItemInterface
    {
        Assert::isInstanceOf($orderItem, SubscriptionPlanAwareInterface::class);
        Assert::notNull($orderItem->getSubscriptionTerms(), 'Only a line with a plan or a frequency starts a subscription.');
        $variant = $orderItem->getVariant();
        Assert::notNull($variant);

        $item = $this->itemFactory->createNew();
        Assert::isInstanceOf($item, SubscriptionItemInterface::class);
        $item->setProductVariant($variant);
        $item->setQuantity($orderItem->getQuantity());
        $item->setUnitPrice($orderItem->getUnitPrice());
        $item->setPlan($orderItem->getSubscriptionPlan());
        $item->setFrequency(null === $orderItem->getSubscriptionPlan() ? $orderItem->getSubscriptionFrequency() : null);
        $item->setOriginOrderItem($orderItem);

        return $item;
    }
}
