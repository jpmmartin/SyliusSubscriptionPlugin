<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Cycle;

use JpmMartin\SyliusSubscriptionPlugin\Cycle\CycleFailureHandlerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Gate\CycleGateKeeper;
use JpmMartin\SyliusSubscriptionPlugin\Gate\CycleGateKeeperInterface;
use JpmMartin\SyliusSubscriptionPlugin\Gate\GateDecision;
use Psr\Clock\ClockInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Gate\ScriptedCycleGate;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;

/**
 * The second cycle of a monthly subscription activated on 1 January, due on 1 February, asked to the
 * test store's gate.
 */
final class CycleGatesTest extends LifecycleTestCase
{
    private ScriptedCycleGate $gate;

    private SubscriptionInterface $subscription;

    private SubscriptionCycleInterface $cycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedCoffeeOrder());
        $this->subscription = $this->subscriptionsByPlan()['COFFEE_MONTHLY'];
        $this->cycle = $this->storedCycles($this->subscription)[1];
        self::assertSame('2027-02-01 09:00', $this->cycle->getScheduledAt()?->format('Y-m-d H:i'));

        $gate = self::getContainer()->get('jpm_martin_sylius_subscription.test.cycle_gate');
        self::assertInstanceOf(ScriptedCycleGate::class, $gate);
        $this->gate = $gate;

        $this->itIsNow('2027-02-01 09:00');
    }

    public function testACycleEveryGateLetsThroughMayPlaceItsOrder(): void
    {
        self::assertTrue($this->admit());

        self::assertSame(SubscriptionCycleInterface::STATE_SCHEDULED, $this->storedCycle()->getState());
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $this->storedSubscription()->getState());
    }

    public function testACycleAGateAsksToWaitIsHeldWithItsReasonAndNoOrder(): void
    {
        $this->gate->decide(GateDecision::wait(new \DateTimeImmutable('2027-02-06 09:00'), 'Waiting for the prescriber to approve the refill.'));

        self::assertFalse($this->admit());

        $cycle = $this->storedCycle();
        self::assertSame(SubscriptionCycleInterface::STATE_ON_HOLD, $cycle->getState());
        self::assertSame('2027-02-06 09:00', $cycle->getHoldUntil()?->format('Y-m-d H:i'));
        self::assertSame('Waiting for the prescriber to approve the refill.', $cycle->getHoldReason());
        self::assertNull($cycle->getOrder());
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $this->storedSubscription()->getState());
    }

    public function testAHeldCycleIsAskedAgainOnEveryRunAndKeepsTheDeadlineItWasFirstGiven(): void
    {
        $this->gate->decide(GateDecision::wait(new \DateTimeImmutable('2027-02-06 09:00'), 'Waiting for the prescriber to approve the refill.'));
        $this->admit();

        $this->itIsNow('2027-02-02 09:00');
        $this->gate->decide(GateDecision::wait(new \DateTimeImmutable('2027-03-01 09:00'), 'The prescriber asked for a consultation.'));
        self::assertFalse($this->admit());
        $cycle = $this->storedCycle();
        self::assertSame(SubscriptionCycleInterface::STATE_ON_HOLD, $cycle->getState());
        self::assertSame('2027-02-06 09:00', $cycle->getHoldUntil()?->format('Y-m-d H:i'));
        self::assertSame('The prescriber asked for a consultation.', $cycle->getHoldReason());

        $this->itIsNow('2027-02-03 09:00');
        $this->gate->decide(GateDecision::pass());
        self::assertTrue($this->admit());
    }

    public function testAHeldCycleWhoseDeadlineArrivesFailsWithoutAnOrderAndTheNextOneIsScheduled(): void
    {
        $this->gate->decide(GateDecision::wait(new \DateTimeImmutable('2027-02-06 09:00'), 'Waiting for the prescriber to approve the refill.'));
        $this->admit();

        $this->itIsNow('2027-02-06 09:00');
        self::assertFalse($this->admit());

        $cycle = $this->storedCycle();
        self::assertSame(SubscriptionCycleInterface::STATE_FAILED, $cycle->getState());
        self::assertSame('Waiting for the prescriber to approve the refill.', $cycle->getCancellationReason());
        self::assertNull($cycle->getOrder());
        $this->assertTheNextCycleIsScheduledAndTheSubscriptionIsStillActive();
    }

    public function testACycleAGateRejectsFailsWithoutAnOrderAndTheNextOneIsScheduled(): void
    {
        $this->gate->decide(GateDecision::reject('The prescription has expired.'));

        self::assertFalse($this->admit());

        $cycle = $this->storedCycle();
        self::assertSame(SubscriptionCycleInterface::STATE_FAILED, $cycle->getState());
        self::assertSame('The prescription has expired.', $cycle->getCancellationReason());
        self::assertNull($cycle->getOrder());
        $this->assertTheNextCycleIsScheduledAndTheSubscriptionIsStillActive();
    }

    public function testWithoutAnyGateEveryCyclePasses(): void
    {
        $this->gate->decide(GateDecision::reject('Not asked: this keeper has no gates.'));

        self::assertTrue($this->admit($this->keeperWithoutGates()));
    }

    private function assertTheNextCycleIsScheduledAndTheSubscriptionIsStillActive(): void
    {
        $subscription = $this->storedSubscription();
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState());
        self::assertSame(1, $subscription->getConsecutiveFailedCycles());
        $next = $this->storedCycles($subscription)[2];
        self::assertSame(SubscriptionCycleInterface::STATE_SCHEDULED, $next->getState());
        self::assertSame('2027-03-01 09:00', $next->getScheduledAt()?->format('Y-m-d H:i'));
    }

    private function admit(?CycleGateKeeperInterface $keeper = null): bool
    {
        if (null === $keeper) {
            $keeper = self::getContainer()->get(CycleGateKeeperInterface::class);
            self::assertInstanceOf(CycleGateKeeperInterface::class, $keeper);
        }

        $admitted = $keeper->admit($this->cycle);
        $this->entityManager()->flush();

        return $admitted;
    }

    private function keeperWithoutGates(): CycleGateKeeper
    {
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');
        /** @var CycleFailureHandlerInterface $failureHandler */
        $failureHandler = self::getContainer()->get('jpm_martin_sylius_subscription.cycle.failure_handler');
        /** @var ClockInterface $clock */
        $clock = self::getContainer()->get('clock');

        return new CycleGateKeeper([], $stateMachine, $failureHandler, $clock);
    }

    private function storedCycle(): SubscriptionCycleInterface
    {
        return $this->storedCycles($this->subscription)[1];
    }

    private function storedSubscription(): SubscriptionInterface
    {
        return $this->subscriptionsByPlan()['COFFEE_MONTHLY'];
    }
}
