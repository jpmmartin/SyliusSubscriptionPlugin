<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Trial;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;

/**
 * Coffee at $100.00 on its monthly plan with 10% off and 14 days of free trial, shipped for free,
 * subscribed to on 1 March; the test store keeps cards with "Card on file", whose gateway authorizes
 * the payment of 0 of an order with nothing else to pay.
 */
final class StartingAFreeTrialTest extends LifecycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->coffeeMonthly->setTrialDays(14);
        $this->entityManager()->flush();
        $this->itIsNow('2027-03-01 09:00');
    }

    public function testTheAuthorizedPaymentOfZeroActivatesItAndItsFirstChargeIsTheRenewalWhenTheTrialEnds(): void
    {
        $order = $this->placedCoffeeOrder();
        self::assertSame(0, $order->getTotal());
        $this->authorize($order);

        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $order->getPaymentState());
        $subscription = $this->subscription();
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState());
        self::assertSame('2027-03-01 09:00', $subscription->getActivatedAt()?->format('Y-m-d H:i'));
        self::assertSame(14, $subscription->getTrialDays());
        self::assertSame(9000, $this->onlyItemOf($subscription)->getUnitPrice(), 'The normal price is frozen: $100.00 less 10%.');
        [$first, $second] = $this->storedCycles($subscription);
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $first->getState());
        self::assertSame([0], $this->recordedUnitPricesOf($first), 'The initial order charged nothing for it.');
        self::assertSame('2027-03-15 09:00', $second->getScheduledAt()?->format('Y-m-d H:i'));

        $this->itIsNow('2027-03-15 09:00');
        $this->runTheCycleCommand();

        [, $second, $third] = $this->storedCycles($this->subscription());
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $second->getState());
        self::assertSame(9000, $second->getOrder()?->getItemsTotal(), 'The first charge.');
        self::assertSame('2027-04-15 09:00', $third->getScheduledAt()?->format('Y-m-d H:i'), 'The calendar follows the interval from there.');
    }

    public function testAnAuthorizationActivatesNothingOnAnOrderThatHasSomethingToPay(): void
    {
        $this->coffeeMonthly->setTrialDays(null);
        $this->entityManager()->flush();
        $order = $this->placedCoffeeOrder();
        self::assertSame(9000, $order->getTotal());

        $this->authorize($order);

        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $order->getPaymentState());
        self::assertSame(SubscriptionInterface::STATE_PENDING, $this->subscription()->getState(), 'Only paying it activates it.');
    }

    public function testAFreeTrialAndALineToPayOfTheSameIntervalStartTwoSubscriptionsActivatedWhenTheOrderIsPaid(): void
    {
        $order = $this->placedBatchOrder();
        self::assertSame(5000, $order->getTotal(), 'Tea is paid, Coffee is free.');
        $this->pay($order);

        $subscriptions = $this->subscriptionsByPlan();
        self::assertSame(['COFFEE_MONTHLY', 'TEA_MONTHLY'], array_keys($subscriptions));
        self::assertSame([14, null], [$subscriptions['COFFEE_MONTHLY']->getTrialDays(), $subscriptions['TEA_MONTHLY']->getTrialDays()]);
        self::assertSame(
            ['2027-03-15 09:00', '2027-04-01 09:00'],
            [$this->storedCycles($subscriptions['COFFEE_MONTHLY'])[1]->getScheduledAt()?->format('Y-m-d H:i'), $this->storedCycles($subscriptions['TEA_MONTHLY'])[1]->getScheduledAt()?->format('Y-m-d H:i')],
            'Each keeps a calendar of its own.',
        );
    }

    /** What the gateway does with a payment it only authorizes. */
    private function authorize(OrderInterface $order): void
    {
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);
        $this->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_AUTHORIZE);
        $this->entityManager()->flush();
    }

    private function subscription(): SubscriptionInterface
    {
        $subscriptions = $this->storedSubscriptions();
        self::assertCount(1, $subscriptions);

        return $subscriptions[0];
    }

    /** @return list<int> */
    private function recordedUnitPricesOf(SubscriptionCycleInterface $cycle): array
    {
        return array_values(array_map(static fn (SubscriptionCycleItemInterface $recorded): int => $recorded->getUnitPrice(), $cycle->getItems()->toArray()));
    }
}
