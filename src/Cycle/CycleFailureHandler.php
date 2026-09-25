<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Cycle;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionChargeAttemptInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionSchedulerInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionCycleTransitions;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Webmozart\Assert\Assert;

/**
 * A failed cycle never stops its subscription by itself. Only a run of them does, and only when the
 * store sets a limit: an expired card would otherwise place a failing order every cycle, for ever.
 */
final class CycleFailureHandler implements CycleFailureHandlerInterface
{
    public function __construct(
        private readonly StateMachineInterface $stateMachine,
        private readonly SubscriptionSchedulerInterface $scheduler,
        private readonly ?int $suspendAfterFailedCycles,
    ) {
    }

    public function fail(SubscriptionCycleInterface $cycle, string $reason): void
    {
        // A cycle settled meanwhile, paid or cancelled, has not failed.
        if (!$this->stateMachine->can($cycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_FAIL)) {
            return;
        }

        $manualRetry = $cycle->isManualRetry();
        $cycle->setCancellationReason($reason);
        $cycle->setNextAttemptAt(null);
        $this->stateMachine->apply($cycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_FAIL);

        // The cycle had already failed and been counted, and the schedule went on without it.
        if ($manualRetry) {
            return;
        }

        $subscription = $cycle->getSubscription();
        Assert::notNull($subscription);
        $subscription->setConsecutiveFailedCycles($subscription->getConsecutiveFailedCycles() + 1);

        if (null !== $this->suspendAfterFailedCycles && $subscription->getConsecutiveFailedCycles() >= $this->suspendAfterFailedCycles) {
            if ($this->stateMachine->can($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_SUSPEND)) {
                // Before the transition, so SubscriptionSuspended says why. Paying only mends a charge:
                // a cycle a gate, an expired hold or nothing to renew failed stays the administrator's.
                $subscription->setSuspendedForUnpaidRenewals(self::failedOnACharge($cycle));
                $this->stateMachine->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_SUSPEND);
            }

            return;
        }

        $this->scheduler->scheduleNext($subscription);
    }

    private static function failedOnACharge(SubscriptionCycleInterface $cycle): bool
    {
        $lastAttempt = $cycle->getAttempts()->last();

        return $lastAttempt instanceof SubscriptionChargeAttemptInterface &&
            \in_array($lastAttempt->getType(), [SubscriptionChargeAttemptInterface::TYPE_CHARGE, SubscriptionChargeAttemptInterface::TYPE_STATUS], true) &&
            \in_array($lastAttempt->getOutcome(), [SubscriptionChargeAttemptInterface::OUTCOME_DECLINED, SubscriptionChargeAttemptInterface::OUTCOME_NOT_ATTEMPTED], true);
    }
}
