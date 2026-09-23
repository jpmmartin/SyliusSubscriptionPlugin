<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Cycle;

use JpmMartin\SyliusSubscriptionPlugin\Cycle\CycleFailureHandler;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Gate\GateDecision;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionSchedulerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Gate\ScriptedCycleGate;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;

/**
 * A monthly Coffee subscription activated on 1 January, with the default limit of three failed cycles
 * in a row. The test store's gate fails a cycle at once, as a decline would once its retries ran out.
 */
final class FailingCyclesTest extends LifecycleTestCase
{
    private ScriptedCycleGate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $gate = self::getContainer()->get('jpm_martin_sylius_subscription.test.cycle_gate');
        self::assertInstanceOf(ScriptedCycleGate::class, $gate);
        $this->gate = $gate;

        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedCoffeeOrder());
    }

    public function testTheThirdCycleFailedInARowSuspendsTheSubscriptionAndSchedulesNothingMore(): void
    {
        $this->failOn('2027-02-01 09:00');
        $this->failOn('2027-03-01 09:00');
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $this->subscription()->getState(), 'Two failures in a row are not enough.');

        $this->failOn('2027-04-01 09:00');

        $subscription = $this->subscription();
        self::assertSame(SubscriptionInterface::STATE_SUSPENDED, $subscription->getState());
        self::assertSame(3, $subscription->getConsecutiveFailedCycles());
        self::assertSame([1 => 'paid', 2 => 'failed', 3 => 'failed', 4 => 'failed'], $this->cycleStates($subscription));
    }

    public function testAPaidCycleBetweenFailuresStartsTheCountAfresh(): void
    {
        $this->failOn('2027-02-01 09:00');
        $this->failOn('2027-03-01 09:00');
        $this->gate->decide(GateDecision::pass());
        $this->itIsNow('2027-04-01 09:00');
        $this->runTheCycleCommand();
        self::assertSame(0, $this->subscription()->getConsecutiveFailedCycles());

        $this->failOn('2027-05-01 09:00');

        $subscription = $this->subscription();
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState());
        self::assertSame(1, $subscription->getConsecutiveFailedCycles());
        self::assertSame([1 => 'paid', 2 => 'failed', 3 => 'failed', 4 => 'paid', 5 => 'failed', 6 => 'scheduled'], $this->cycleStates($subscription));
    }

    public function testAStoreThatNeverSuspendsKeepsSchedulingAfterAnyNumberOfFailures(): void
    {
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');
        /** @var SubscriptionSchedulerInterface $scheduler */
        $scheduler = self::getContainer()->get('jpm_martin_sylius_subscription.schedule.scheduler');
        $failureHandler = new CycleFailureHandler($stateMachine, $scheduler, null);

        $subscription = $this->subscription();
        for ($failure = 1; $failure <= 5; ++$failure) {
            $openCycle = $scheduler->findOpenCycle($subscription);
            self::assertNotNull($openCycle);
            $failureHandler->fail($openCycle, 'The prescription has expired.');
        }
        $this->entityManager()->flush();

        $subscription = $this->subscription();
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState());
        self::assertSame(5, $subscription->getConsecutiveFailedCycles());
        self::assertSame(SubscriptionCycleInterface::STATE_SCHEDULED, $this->storedCycles($subscription)[6]->getState());
    }

    private function failOn(string $dateTime): void
    {
        $this->gate->decide(GateDecision::reject('The prescription has expired.'));
        $this->itIsNow($dateTime);
        $this->runTheCycleCommand();
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
