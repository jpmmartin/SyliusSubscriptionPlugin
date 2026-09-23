<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Order\OrderTransitions;
use Sylius\Component\Payment\PaymentTransitions;

/** One test per event of the initial order the subscriptions follow: paid, cancelled and refunded. */
final class InitialOrderLifecycleTest extends LifecycleTestCase
{
    public function testAnInitialOrderPaidTwoDaysLaterActivatesItsSubscriptionThatDay(): void
    {
        $this->itIsNow('2027-01-29 10:00');
        $order = $this->placedCoffeeOrder();

        $this->itIsNow('2027-01-31 16:30');
        $this->pay($order);

        $subscription = $this->subscriptionsByPlan()['COFFEE_MONTHLY'];
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState());
        self::assertSame('2027-01-31 16:30', $subscription->getActivatedAt()?->format('Y-m-d H:i'));

        $cycles = $this->storedCycles($subscription);
        self::assertCount(2, $cycles);
        [$first, $second] = $cycles;
        self::assertSame(1, $first->getNumber());
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $first->getState());
        self::assertSame($order->getId(), $first->getOrder()?->getId());
        self::assertSame('2027-01-31 16:30', $first->getScheduledAt()?->format('Y-m-d H:i'));
        self::assertSame(2, $second->getNumber());
        self::assertSame(SubscriptionCycleInterface::STATE_SCHEDULED, $second->getState());
        self::assertNull($second->getOrder());
        self::assertSame('2027-02-28 16:30', $second->getScheduledAt()?->format('Y-m-d H:i'), 'One month after the day it was paid.');
    }

    public function testAnInitialOrderCancelledUnpaidCancelsItsSubscriptionWithoutAnyCycle(): void
    {
        $order = $this->placedCoffeeOrder();

        $this->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);
        $this->entityManager()->flush();

        $subscription = $this->subscriptionsByPlan()['COFFEE_MONTHLY'];
        self::assertSame(SubscriptionInterface::STATE_CANCELLED, $subscription->getState());
        self::assertCount(0, $this->storedCycles($subscription));
    }

    public function testAnInitialOrderRefundedInFullLeavesItsSubscriptionActive(): void
    {
        $order = $this->placedCoffeeOrder();
        $this->pay($order);

        $payment = $order->getLastPayment(PaymentInterface::STATE_COMPLETED);
        self::assertNotNull($payment);
        $this->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        $this->entityManager()->flush();

        $subscription = $this->subscriptionsByPlan()['COFFEE_MONTHLY'];
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState());
        self::assertSame(
            [1 => SubscriptionCycleInterface::STATE_PAID, 2 => SubscriptionCycleInterface::STATE_SCHEDULED],
            $this->cycleStates($subscription),
        );
    }

    public function testAPaidInitialOrderThatIsCancelledLeavesItsSubscriptionActive(): void
    {
        $order = $this->placedCoffeeOrder();
        $this->pay($order);

        $this->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);
        $this->entityManager()->flush();

        $subscription = $this->subscriptionsByPlan()['COFFEE_MONTHLY'];
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState());
        self::assertSame(
            [1 => SubscriptionCycleInterface::STATE_PAID, 2 => SubscriptionCycleInterface::STATE_SCHEDULED],
            $this->cycleStates($subscription),
        );
    }

    public function testASubscriptionWhosePlanHasASingleCycleCompletesWhenItsInitialOrderIsPaid(): void
    {
        $this->coffeeMonthly->setMaxCycles(1);
        $order = $this->placedCoffeeOrder();

        $this->pay($order);

        $subscription = $this->subscriptionsByPlan()['COFFEE_MONTHLY'];
        self::assertSame(SubscriptionInterface::STATE_COMPLETED, $subscription->getState());
        self::assertSame([1 => SubscriptionCycleInterface::STATE_PAID], $this->cycleStates($subscription));
    }

    /** @return array<int, string> as stored, by cycle number */
    private function cycleStates(SubscriptionInterface $subscription): array
    {
        $states = [];
        foreach ($this->storedCycles($subscription) as $cycle) {
            $states[$cycle->getNumber()] = $cycle->getState();
        }

        return $states;
    }
}
