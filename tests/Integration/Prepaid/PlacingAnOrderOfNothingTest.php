<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Prepaid;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Order\RenewalOrderPlacerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionSchedulerInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Core\OrderShippingStates;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;

/**
 * A prepaid delivery is a renewal order of nothing: its lines at 0, shipped for free. Sylius completes
 * it without a payment, as paid, with its shipment ready to be prepared and sent like any other.
 */
final class PlacingAnOrderOfNothingTest extends LifecycleTestCase
{
    public function testARenewalOrderOfNothingIsCompletedWithoutAPaymentAndReadyToShip(): void
    {
        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedCoffeeOrder());
        $subscription = $this->storedSubscriptions()[0];
        // What a prepaid delivery's line costs.
        $this->onlyItemOf($subscription)->setUnitPrice(0);
        $this->entityManager()->flush();

        $this->itIsNow('2027-02-01 09:00');
        /** @var SubscriptionSchedulerInterface $scheduler */
        $scheduler = self::getContainer()->get('jpm_martin_sylius_subscription.schedule.scheduler');
        $cycle = $scheduler->findOpenCycle($subscription);
        self::assertInstanceOf(SubscriptionCycleInterface::class, $cycle);
        /** @var RenewalOrderPlacerInterface $placer */
        $placer = self::getContainer()->get(RenewalOrderPlacerInterface::class);
        $order = $placer->place($cycle);
        $this->entityManager()->flush();

        self::assertNotNull($order);
        self::assertSame(0, $order->getTotal());
        self::assertSame(0, $order->getShippingTotal());
        self::assertCount(0, $order->getPayments(), 'Sylius drops the payments of an order of 0.');
        self::assertSame(OrderCheckoutStates::STATE_COMPLETED, $order->getCheckoutState());
        self::assertSame(OrderPaymentStates::STATE_PAID, $order->getPaymentState());
        self::assertSame(OrderShippingStates::STATE_READY, $order->getShippingState());
        self::assertCount(1, $order->getShipments());
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState());
    }
}
