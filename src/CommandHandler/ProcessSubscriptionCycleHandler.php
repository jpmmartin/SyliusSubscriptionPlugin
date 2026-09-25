<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\CommandHandler;

use JpmMartin\SyliusSubscriptionPlugin\Command\ProcessSubscriptionCycle;
use JpmMartin\SyliusSubscriptionPlugin\Cycle\CycleChargerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Cycle\CycleFailureHandlerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionChargeAttemptInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Gate\CycleGateKeeperInterface;
use JpmMartin\SyliusSubscriptionPlugin\Order\RenewalOrderPlacerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionCycleRepositoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\MissedCyclePolicyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionSchedulerInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionCycleTransitions;
use Psr\Clock\ClockInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Webmozart\Assert\Assert;

/**
 * Takes a due cycle one step further. A cycle that changed since it was found due has been dealt with
 * by another run or another delivery of the same message, and is left alone. Whatever this handler
 * changes is stored with the cycle's version checked, so two handlers can never both go ahead.
 *
 * An administrator's retry is charged once, by the retry itself: here it is only reconciled, even
 * while its subscription is paused or suspended.
 *
 * A scheduled cycle the missed cycle policy no longer holds due is cancelled without an order, and the
 * next is scheduled: it is not a failed cycle.
 */
final class ProcessSubscriptionCycleHandler
{
    public const NOTHING_TO_RENEW = 'None of the subscription\'s items could be renewed.';

    public const MISSED = 'Skipped: the date of the cycle after it had come too before it could be renewed.';

    /** @param SubscriptionCycleRepositoryInterface<SubscriptionCycleInterface> $cycleRepository */
    public function __construct(
        private readonly SubscriptionCycleRepositoryInterface $cycleRepository,
        private readonly CycleGateKeeperInterface $gateKeeper,
        private readonly RenewalOrderPlacerInterface $renewalOrderPlacer,
        private readonly CycleChargerInterface $cycleCharger,
        private readonly CycleFailureHandlerInterface $failureHandler,
        private readonly StateMachineInterface $stateMachine,
        private readonly ClockInterface $clock,
        private readonly MissedCyclePolicyInterface $missedCyclePolicy,
        private readonly SubscriptionSchedulerInterface $scheduler,
    ) {
    }

    public function __invoke(ProcessSubscriptionCycle $command): void
    {
        $cycle = $this->cycleRepository->findUnchangedSince($command->cycleId, $command->version);
        if (null === $cycle || !$this->isFollowedUp($cycle)) {
            return;
        }

        $now = $this->clock->now();

        if (SubscriptionCycleInterface::STATE_SCHEDULED === $cycle->getState() && $cycle->getScheduledAt() > $now) {
            return;
        }

        if (SubscriptionCycleInterface::STATE_SCHEDULED === $cycle->getState() && !$this->missedCyclePolicy->isStillDue($cycle, $now)) {
            $this->skip($cycle);

            return;
        }

        if (\in_array($cycle->getState(), [SubscriptionCycleInterface::STATE_SCHEDULED, SubscriptionCycleInterface::STATE_ON_HOLD], true)) {
            if ($this->gateKeeper->admit($cycle)) {
                $this->placeAndCharge($cycle);
            }

            return;
        }

        $nextAttemptAt = $cycle->getNextAttemptAt();
        if (SubscriptionCycleInterface::STATE_AWAITING_PAYMENT !== $cycle->getState() || null === $nextAttemptAt || $nextAttemptAt > $now) {
            return;
        }

        $lastAttempt = $cycle->getAttempts()->last();
        if ($lastAttempt instanceof SubscriptionChargeAttemptInterface && SubscriptionChargeAttemptInterface::OUTCOME_UNKNOWN === $lastAttempt->getOutcome()) {
            $this->cycleCharger->reconcile($cycle);

            return;
        }

        if (!$cycle->isManualRetry()) {
            $this->cycleCharger->charge($cycle);
        }
    }

    private function isFollowedUp(SubscriptionCycleInterface $cycle): bool
    {
        $state = $cycle->getSubscription()?->getState();

        return SubscriptionInterface::STATE_ACTIVE === $state || (
            $cycle->isManualRetry() && \in_array($state, [SubscriptionInterface::STATE_PAUSED, SubscriptionInterface::STATE_SUSPENDED], true)
        );
    }

    private function skip(SubscriptionCycleInterface $cycle): void
    {
        $cycle->setCancellationReason(self::MISSED);
        $this->stateMachine->apply($cycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_CANCEL);

        $subscription = $cycle->getSubscription();
        Assert::notNull($subscription);
        $this->scheduler->scheduleNext($subscription);
    }

    private function placeAndCharge(SubscriptionCycleInterface $cycle): void
    {
        if (null === $this->renewalOrderPlacer->place($cycle)) {
            $this->failureHandler->fail($cycle, self::NOTHING_TO_RENEW);

            return;
        }

        $this->stateMachine->apply($cycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_PLACE_ORDER);
        $this->cycleCharger->charge($cycle);
    }
}
