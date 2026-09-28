<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Prepaid;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;

/**
 * Coffee at $100.00 on its monthly plan with 10% off, $90.00 a delivery, charged by blocks of three
 * deliveries: $270.00 a charge, one delivery a month.
 */
final class PrepaidBlockTest extends LifecycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->coffeeMonthly->setDeliveriesPerCharge(3);
        $this->entityManager()->flush();
        $this->itIsNow('2027-01-01 09:00');
    }

    public function testACartLineOnAPrepaidPlanCostsItsWholeBlock(): void
    {
        $cart = $this->cart();
        $line = $this->addLine($cart, $this->coffee, 1, $this->coffeeMonthly);

        self::assertSame(27000, $line->getUnitPrice(), 'Three deliveries of $90.00.');
        self::assertSame(27000, $cart->getTotal());
    }

    public function testTheInitialOrderChargesTheFirstBlockAndItsOtherDeliveriesAreStillToCome(): void
    {
        $order = $this->placedCoffeeOrder();
        self::assertSame(27000, $order->getItemsTotal());
        $this->pay($order);

        $subscription = $this->storedSubscriptions()[0];
        self::assertSame([1, SubscriptionIntervalUnit::Month], [$subscription->getDeliveryIntervalCount(), $subscription->getDeliveryIntervalUnit()]);
        self::assertSame([3, SubscriptionIntervalUnit::Month], [$subscription->getBillingIntervalCount(), $subscription->getBillingIntervalUnit()]);
        self::assertSame(3, $subscription->getDeliveriesPerCharge());
        self::assertSame(9000, $this->onlyItemOf($subscription)->getUnitPrice(), 'The item freezes the price of one delivery.');
        self::assertNull($this->onlyItemOf($subscription)->getIntroductoryUnitPrice(), 'A block is no introductory price.');
        self::assertSame(2, $subscription->getPrepaidDeliveriesLeft(), 'February and March are paid for.');

        [$first, $second] = $this->storedCycles($subscription);
        $recorded = $first->getItems()->first();
        self::assertInstanceOf(SubscriptionCycleItemInterface::class, $recorded);
        self::assertSame(27000, $recorded->getUnitPrice());
        self::assertSame('2027-02-01 09:00', $second->getScheduledAt()?->format('Y-m-d H:i'), 'One delivery a month.');
    }

    public function testLinesPrepaidByBlocksAndLinesThatAreNotStartSubscriptionsOfTheirOwn(): void
    {
        $this->pay($this->placedBatchOrder());

        $subscriptions = $this->subscriptionsByPlan();
        self::assertSame(['COFFEE_MONTHLY', 'TEA_MONTHLY'], array_keys($subscriptions));
        self::assertSame([3, 1], [$subscriptions['COFFEE_MONTHLY']->getDeliveriesPerCharge(), $subscriptions['TEA_MONTHLY']->getDeliveriesPerCharge()]);
    }
}
