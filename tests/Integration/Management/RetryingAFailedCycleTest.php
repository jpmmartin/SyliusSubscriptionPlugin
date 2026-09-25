<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Management;

use JpmMartin\SyliusSubscriptionPlugin\CommandHandler\ProcessSubscriptionCycleHandler;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Gate\GateDecision;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionCycleRetrierInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Gate\ScriptedCycleGate;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

/**
 * A monthly Coffee subscription activated on 1 January, whose February cycle failed once its retries
 * ran out on 8 February; the March cycle is scheduled.
 */
final class RetryingAFailedCycleTest extends LifecycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedCoffeeOrder());
    }

    public function testAnApprovedRetryPaysTheCycleWithItsNewOrderAndLeavesTheCalendarAlone(): void
    {
        $this->failTheFebruaryCycle();
        $failedOrder = $this->cycle(2)->getOrder();
        self::assertNotNull($failedOrder);

        $this->itIsNow('2027-02-10 11:00');
        $this->retry($this->cycle(2));

        $cycle = $this->cycle(2);
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $cycle->getState());
        self::assertFalse($cycle->isManualRetry());
        self::assertNull($cycle->getCancellationReason());
        self::assertNotSame($failedOrder->getId(), $cycle->getOrder()?->getId(), 'The retry placed a new order.');
        self::assertSame(OrderPaymentStates::STATE_PAID, $cycle->getOrder()?->getPaymentState());
        self::assertSame(OrderInterface::STATE_CANCELLED, $this->reloaded($failedOrder)->getState(), 'The failed order stays cancelled.');
        self::assertSame(['declined', 'declined', 'declined', 'declined', 'approved'], $this->outcomesOf($cycle));
        self::assertSame(2, $this->onlyItemOf($this->subscription())->getPaidCycles());

        $subscription = $this->subscription();
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState());
        self::assertSame(0, $subscription->getConsecutiveFailedCycles());
        self::assertSame([1 => 'paid', 2 => 'paid', 3 => 'scheduled'], $this->cycleStates($subscription), 'No cycle was added.');
        self::assertSame('2027-03-01 09:00', $this->cycle(3)->getScheduledAt()?->format('Y-m-d H:i'));
    }

    public function testADeclinedRetryLeavesTheCycleFailedCancelsItsNewOrderAndIsNotRetriedByTheScheduler(): void
    {
        $this->failTheFebruaryCycle();

        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Card expired.');
        $this->itIsNow('2027-02-10 11:00');
        $this->retry($this->cycle(2));

        $cycle = $this->cycle(2);
        self::assertSame(SubscriptionCycleInterface::STATE_FAILED, $cycle->getState());
        self::assertSame('Card expired.', $cycle->getCancellationReason());
        self::assertFalse($cycle->isManualRetry());
        self::assertNull($cycle->getNextAttemptAt());
        self::assertSame(OrderInterface::STATE_CANCELLED, $cycle->getOrder()?->getState());
        self::assertSame(1, $this->subscription()->getConsecutiveFailedCycles(), 'The cycle had already been counted.');

        $this->itIsNow('2027-02-20 11:00');
        $this->runTheCycleCommand();
        self::assertSame(['capture', 'capture', 'capture', 'capture', 'capture'], $this->scriptedGateway()->requests());
        self::assertSame([1 => 'paid', 2 => 'failed', 3 => 'scheduled'], $this->cycleStates($this->subscription()));
    }

    public function testASuccessfulRetryLeavesASuspendedSubscriptionSuspended(): void
    {
        $this->gate()->decide(GateDecision::reject('The prescription has expired.'));
        foreach (['2027-02-01 09:00', '2027-03-01 09:00', '2027-04-01 09:00'] as $dateTime) {
            $this->itIsNow($dateTime);
            $this->runTheCycleCommand();
        }
        $this->gate()->decide(GateDecision::pass());
        self::assertSame(SubscriptionInterface::STATE_SUSPENDED, $this->subscription()->getState());

        $this->itIsNow('2027-04-05 11:00');
        $this->retry($this->cycle(4));

        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $this->cycle(4)->getState());
        $subscription = $this->subscription();
        self::assertSame(SubscriptionInterface::STATE_SUSPENDED, $subscription->getState());
        self::assertSame([1 => 'paid', 2 => 'failed', 3 => 'failed', 4 => 'paid'], $this->cycleStates($subscription), 'A suspended subscription schedules nothing.');
    }

    public function testARetryPaidAfterTheNextCycleUsedUpThePlanCompletesTheSubscriptionAndCancelsItsOpenCycle(): void
    {
        $this->coffeeMonthly->setMaxCycles(3);
        $this->entityManager()->flush();
        $this->failTheFebruaryCycle();
        $this->itIsNow('2027-03-01 09:00');
        $this->runTheCycleCommand();
        self::assertSame([1 => 'paid', 2 => 'failed', 3 => 'paid', 4 => 'scheduled'], $this->cycleStates($this->subscription()));

        $this->itIsNow('2027-03-10 11:00');
        $this->retry($this->cycle(2));

        $subscription = $this->subscription();
        self::assertSame(SubscriptionInterface::STATE_COMPLETED, $subscription->getState());
        self::assertSame(3, $this->onlyItemOf($subscription)->getPaidCycles());
        self::assertSame([1 => 'paid', 2 => 'paid', 3 => 'paid', 4 => 'cancelled'], $this->cycleStates($subscription));
    }

    public function testASuspendedSubscriptionWhoseRetryPaidItsLastCycleCompletesWhenReactivated(): void
    {
        $this->coffeeMonthly->setMaxCycles(3);
        $this->entityManager()->flush();
        $this->failTheFebruaryCycle();
        $this->itIsNow('2027-03-01 09:00');
        $this->runTheCycleCommand();
        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_SUSPEND);
        $this->entityManager()->flush();

        $this->itIsNow('2027-03-10 11:00');
        $this->retry($this->cycle(2));
        self::assertSame(SubscriptionInterface::STATE_SUSPENDED, $this->subscription()->getState(), 'The retry leaves a suspended subscription suspended.');
        self::assertFalse($this->onlyItemOf($this->subscription())->isRenewable());

        $this->itIsNow('2027-03-20 11:00');
        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_REACTIVATE);
        $this->entityManager()->flush();

        $subscription = $this->subscription();
        self::assertSame(SubscriptionInterface::STATE_COMPLETED, $subscription->getState());
        self::assertSame([1 => 'paid', 2 => 'paid', 3 => 'paid', 4 => 'cancelled'], $this->cycleStates($subscription), 'No cycle is scheduled.');
    }

    public function testASuccessfulRetryLeavesAPausedSubscriptionPaused(): void
    {
        $this->failTheFebruaryCycle();
        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_PAUSE);
        $this->entityManager()->flush();

        $this->itIsNow('2027-02-10 11:00');
        self::assertTrue($this->retrier()->canRetry($this->cycle(2)));
        $this->retry($this->cycle(2));

        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $this->cycle(2)->getState());
        $subscription = $this->subscription();
        self::assertSame(SubscriptionInterface::STATE_PAUSED, $subscription->getState());
        self::assertSame([1 => 'paid', 2 => 'paid', 3 => 'cancelled'], $this->cycleStates($subscription), 'A paused subscription schedules nothing.');
    }

    public function testAPausedSubscriptionWhoseRetryPaidItsLastCycleCompletesWhenResumed(): void
    {
        $this->coffeeMonthly->setMaxCycles(3);
        $this->entityManager()->flush();
        $this->failTheFebruaryCycle();
        $this->itIsNow('2027-03-01 09:00');
        $this->runTheCycleCommand();
        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_PAUSE);
        $this->entityManager()->flush();

        $this->itIsNow('2027-03-10 11:00');
        $this->retry($this->cycle(2));
        self::assertSame(SubscriptionInterface::STATE_PAUSED, $this->subscription()->getState(), 'The retry leaves a paused subscription paused.');
        self::assertFalse($this->onlyItemOf($this->subscription())->isRenewable());

        $this->itIsNow('2027-03-20 11:00');
        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_RESUME);
        $this->entityManager()->flush();

        $subscription = $this->subscription();
        self::assertSame(SubscriptionInterface::STATE_COMPLETED, $subscription->getState());
        self::assertSame([1 => 'paid', 2 => 'paid', 3 => 'paid', 4 => 'cancelled'], $this->cycleStates($subscription), 'No cycle is scheduled.');
    }

    public function testARetryWithNoAnswerIsReconciledByTheSchedulerWhileTheSubscriptionIsPaused(): void
    {
        $this->failTheFebruaryCycle();
        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_PAUSE);
        $this->entityManager()->flush();

        $this->scriptedGateway()->willAnswer(ScriptedGateway::NO_ANSWER);
        $this->itIsNow('2027-02-10 11:00');
        $this->retry($this->cycle(2));
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $this->cycle(2)->getState());

        $this->scriptedGateway()->willAnswer(ScriptedGateway::APPROVE);
        $this->itIsNow('2027-02-10 12:00');
        $this->runTheCycleCommand();

        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $this->cycle(2)->getState(), 'The retry is reconciled while paused.');
        self::assertSame(
            ['capture', 'capture', 'capture', 'capture', 'capture', 'status'],
            $this->scriptedGateway()->requests(),
        );
        self::assertSame(SubscriptionInterface::STATE_PAUSED, $this->subscription()->getState());
    }

    public function testARetryWithNoAnswerIsReconciledByTheSchedulerEvenWhileTheSubscriptionIsSuspendedAndNeverChargedAgain(): void
    {
        $this->failTheFebruaryCycle();
        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_SUSPEND);
        $this->entityManager()->flush();

        $this->scriptedGateway()->willAnswer(ScriptedGateway::NO_ANSWER);
        $this->itIsNow('2027-02-10 11:00');
        $this->retry($this->cycle(2));
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $this->cycle(2)->getState());
        self::assertTrue($this->cycle(2)->isManualRetry());

        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Do not honour.');
        $this->itIsNow('2027-02-10 12:00');
        $this->runTheCycleCommand();

        $cycle = $this->cycle(2);
        self::assertSame(SubscriptionCycleInterface::STATE_FAILED, $cycle->getState(), 'A retry reported declined fails at once.');
        self::assertSame('Do not honour.', $cycle->getCancellationReason());
        self::assertSame(
            ['capture', 'capture', 'capture', 'capture', 'capture', 'status'],
            $this->scriptedGateway()->requests(),
        );
        self::assertSame(SubscriptionInterface::STATE_SUSPENDED, $this->subscription()->getState());
    }

    public function testTheScheduleGoesOnAsUsualWhileARetryAwaitsItsOutcome(): void
    {
        $this->failTheFebruaryCycle();
        $this->scriptedGateway()->willAnswer(ScriptedGateway::NO_ANSWER);
        $this->itIsNow('2027-02-10 11:00');
        $this->retry($this->cycle(2));

        $this->gate()->decide(GateDecision::reject('The prescription has expired.'));
        $this->scriptedGateway()->willAnswer(ScriptedGateway::NO_ANSWER);
        $this->itIsNow('2027-03-01 09:00');
        $this->runTheCycleCommand();

        $subscription = $this->subscription();
        self::assertSame([1 => 'paid', 2 => 'awaiting_payment', 3 => 'failed', 4 => 'scheduled'], $this->cycleStates($subscription), 'The retry is not the open cycle.');
        self::assertSame('2027-04-01 09:00', $this->cycle(4)->getScheduledAt()?->format('Y-m-d H:i'));
        self::assertSame(2, $subscription->getConsecutiveFailedCycles());
    }

    public function testSuspendingTheSubscriptionLeavesARetryAwaitingItsOutcomeToBeReconciled(): void
    {
        $this->failTheFebruaryCycle();
        $this->scriptedGateway()->willAnswer(ScriptedGateway::NO_ANSWER);
        $this->itIsNow('2027-02-10 11:00');
        $this->retry($this->cycle(2));

        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_SUSPEND);
        $this->entityManager()->flush();
        self::assertSame([1 => 'paid', 2 => 'awaiting_payment', 3 => 'cancelled'], $this->cycleStates($this->subscription()), 'Only the open cycle is cancelled.');

        $this->itIsNow('2027-02-10 12:00');
        $this->runTheCycleCommand();

        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $this->cycle(2)->getState());
        self::assertSame(SubscriptionInterface::STATE_SUSPENDED, $this->subscription()->getState());
    }

    public function testPausingTheSubscriptionLeavesARetryAwaitingItsOutcomeToBeReconciled(): void
    {
        $this->failTheFebruaryCycle();
        $this->scriptedGateway()->willAnswer(ScriptedGateway::NO_ANSWER);
        $this->itIsNow('2027-02-10 11:00');
        $this->retry($this->cycle(2));

        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_PAUSE);
        $this->entityManager()->flush();
        self::assertSame([1 => 'paid', 2 => 'awaiting_payment', 3 => 'cancelled'], $this->cycleStates($this->subscription()), 'Only the open cycle is cancelled.');

        $this->itIsNow('2027-02-10 12:00');
        $this->runTheCycleCommand();

        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $this->cycle(2)->getState());
        self::assertSame(SubscriptionInterface::STATE_PAUSED, $this->subscription()->getState());
    }

    public function testASuspensionForFailuresInARowLeavesARetryAwaitingItsOutcomeToBeReconciled(): void
    {
        $this->gate()->decide(GateDecision::reject('The prescription has expired.'));
        foreach (['2027-02-01 09:00', '2027-03-01 09:00'] as $dateTime) {
            $this->itIsNow($dateTime);
            $this->runTheCycleCommand();
        }
        $this->scriptedGateway()->willAnswer(ScriptedGateway::NO_ANSWER);
        $this->itIsNow('2027-03-05 11:00');
        $this->retry($this->cycle(3));

        // The retry is asked about again, still unanswered, and April's cycle is the third failure in a row.
        $this->scriptedGateway()->willAnswer(ScriptedGateway::NO_ANSWER);
        $this->itIsNow('2027-04-01 09:00');
        $this->runTheCycleCommand();
        self::assertSame(SubscriptionInterface::STATE_SUSPENDED, $this->subscription()->getState());
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $this->cycle(3)->getState());

        $this->itIsNow('2027-04-02 09:00');
        $this->runTheCycleCommand();

        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $this->cycle(3)->getState());
        self::assertSame(SubscriptionInterface::STATE_SUSPENDED, $this->subscription()->getState());
    }

    public function testCancellingTheSubscriptionCancelsARetryAwaitingItsOutcome(): void
    {
        $this->failTheFebruaryCycle();
        $this->scriptedGateway()->willAnswer(ScriptedGateway::NO_ANSWER);
        $this->itIsNow('2027-02-10 11:00');
        $this->retry($this->cycle(2));

        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_CANCEL);
        $this->entityManager()->flush();

        self::assertSame([1 => 'paid', 2 => 'cancelled', 3 => 'cancelled'], $this->cycleStates($this->subscription()));
    }

    public function testARetryWithNothingAvailablePlacesNoOrderAndLeavesTheCycleFailed(): void
    {
        $this->failTheFebruaryCycle();
        $coffee = $this->entityManager()->find($this->coffee::class, $this->coffee->getId());
        self::assertNotNull($coffee);
        $coffee->setEnabled(false);
        $this->entityManager()->flush();

        $this->retry($this->cycle(2));

        $cycle = $this->cycle(2);
        self::assertSame(SubscriptionCycleInterface::STATE_FAILED, $cycle->getState());
        self::assertSame(ProcessSubscriptionCycleHandler::NOTHING_TO_RENEW, $cycle->getCancellationReason());
        self::assertSame(1, $this->subscription()->getConsecutiveFailedCycles());
        self::assertCount(4, $cycle->getAttempts(), 'Nothing was charged.');
    }

    public function testOnlyAFailedCycleOfAnActivePausedOrSuspendedSubscriptionCanBeRetried(): void
    {
        $this->failTheFebruaryCycle();
        $retrier = $this->retrier();

        self::assertTrue($retrier->canRetry($this->cycle(2)));
        self::assertFalse($retrier->canRetry($this->cycle(1)), 'A paid cycle.');
        self::assertFalse($retrier->canRetry($this->cycle(3)), 'A scheduled cycle.');

        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_CANCEL);
        $this->entityManager()->flush();
        self::assertFalse($retrier->canRetry($this->cycle(2)), 'A failed cycle of a cancelled subscription.');

        $this->expectException(\InvalidArgumentException::class);
        $retrier->retry($this->cycle(2));
    }

    /** The February cycle is declined on the 1st and on each retry, until it fails on the 8th. */
    private function failTheFebruaryCycle(): void
    {
        foreach (['2027-02-01 09:00', '2027-02-02 09:00', '2027-02-04 09:00', '2027-02-08 09:00'] as $dateTime) {
            $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
            $this->itIsNow($dateTime);
            $this->runTheCycleCommand();
        }
        self::assertSame(SubscriptionCycleInterface::STATE_FAILED, $this->cycle(2)->getState());
    }

    /** What the admin's action does, in a fresh entity manager like a new request. */
    private function retry(SubscriptionCycleInterface $cycle): void
    {
        $this->retrier()->retry($cycle);
        $this->entityManager()->flush();
        $this->entityManager()->clear();
    }

    private function retrier(): SubscriptionCycleRetrierInterface
    {
        $retrier = self::getContainer()->get(SubscriptionCycleRetrierInterface::class);
        self::assertInstanceOf(SubscriptionCycleRetrierInterface::class, $retrier);

        return $retrier;
    }

    private function gate(): ScriptedCycleGate
    {
        $gate = self::getContainer()->get('jpm_martin_sylius_subscription.test.cycle_gate');
        self::assertInstanceOf(ScriptedCycleGate::class, $gate);

        return $gate;
    }

    private function subscription(): SubscriptionInterface
    {
        return $this->subscriptionsByPlan()['COFFEE_MONTHLY'];
    }

    private function cycle(int $number): SubscriptionCycleInterface
    {
        return $this->storedCycles($this->subscription())[$number - 1];
    }

    private function reloaded(OrderInterface $order): OrderInterface
    {
        $reloaded = $this->entityManager()->find($order::class, $order->getId());
        self::assertInstanceOf(OrderInterface::class, $reloaded);

        return $reloaded;
    }

    /** @return list<string> */
    private function outcomesOf(SubscriptionCycleInterface $cycle): array
    {
        $outcomes = [];
        foreach ($cycle->getAttempts() as $attempt) {
            $outcomes[] = $attempt->getOutcome();
        }

        return $outcomes;
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
