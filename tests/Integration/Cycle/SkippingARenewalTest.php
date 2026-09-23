<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Cycle;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Gate\GateDecision;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\OrderTransitions;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Gate\ScriptedCycleGate;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

/** A monthly Coffee subscription activated on 1 January, whose February cycle failed. */
final class SkippingARenewalTest extends LifecycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedCoffeeOrder());

        $gate = self::getContainer()->get('jpm_martin_sylius_subscription.test.cycle_gate');
        self::assertInstanceOf(ScriptedCycleGate::class, $gate);
        $gate->decide(GateDecision::reject('The prescription has expired.'));
        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();
        $gate->decide(GateDecision::pass());
        self::assertSame(1, $this->subscription()->getConsecutiveFailedCycles());
    }

    public function testCancellingARenewalOrderAwaitingPaymentCancelsItsCycleSchedulesTheNextAndCountsNoFailure(): void
    {
        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
        $this->itIsNow('2027-03-01 09:00');
        $this->runTheCycleCommand();

        $this->itIsNow('2027-03-01 15:00');
        $this->cancel($this->orderOfCycle(3));

        $subscription = $this->subscription();
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState());
        self::assertSame(1, $subscription->getConsecutiveFailedCycles(), 'A skipped renewal is not a failure.');
        self::assertSame([1 => 'paid', 2 => 'failed', 3 => 'cancelled', 4 => 'scheduled'], $this->cycleStates($subscription));
        self::assertSame('2027-04-01 09:00', $this->storedCycles($subscription)[3]->getScheduledAt()?->format('Y-m-d H:i'));

        $this->itIsNow('2027-03-02 09:00');
        $this->runTheCycleCommand();
        self::assertSame(['capture'], $this->scriptedGateway()->requests(), 'The cancelled cycle is not retried.');
    }

    public function testCancellingAPaidRenewalOrderLeavesItsCyclePaid(): void
    {
        $this->itIsNow('2027-03-01 09:00');
        $this->runTheCycleCommand();

        $this->cancel($this->orderOfCycle(3));

        $subscription = $this->subscription();
        self::assertSame(0, $subscription->getConsecutiveFailedCycles());
        self::assertSame([1 => 'paid', 2 => 'failed', 3 => 'paid', 4 => 'scheduled'], $this->cycleStates($subscription));
    }

    private function cancel(OrderInterface $order): void
    {
        $this->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);
        $this->entityManager()->flush();
    }

    private function orderOfCycle(int $number): OrderInterface
    {
        $order = $this->storedCycles($this->subscription())[$number - 1]->getOrder();
        self::assertNotNull($order);

        return $order;
    }

    private function subscription(): SubscriptionInterface
    {
        return $this->subscriptionsByPlan()['COFFEE_MONTHLY'];
    }

    /** @return array<int, string> */
    private function cycleStates(SubscriptionInterface $subscription): array
    {
        $states = [];
        foreach ($this->storedCycles($subscription) as $cycle) {
            $states[$cycle->getNumber()] = $cycle->getState();
        }

        return $states;
    }
}
