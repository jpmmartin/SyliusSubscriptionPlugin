<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Management;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\EventPublisher;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalSkipped;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionSchedulerInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionCycleTransitions;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Webmozart\Assert\Assert;

/**
 * Skipping cancels the open cycle while nothing has been ordered for it, so there is nothing to charge
 * or refund, and the scheduler places the next cycle on the following date of the calendar, as after
 * any cancelled cycle. The skips in a row are the skipped cycles right before the open one: a paid,
 * failed or otherwise cancelled cycle ends the run.
 */
final class SubscriptionRenewalSkipper implements SubscriptionRenewalSkipperInterface
{
    private const SKIPPABLE_CYCLE_STATES = [SubscriptionCycleInterface::STATE_SCHEDULED, SubscriptionCycleInterface::STATE_ON_HOLD];

    public function __construct(
        private readonly StateMachineInterface $stateMachine,
        private readonly SubscriptionSchedulerInterface $scheduler,
        private readonly EventPublisher $eventPublisher,
        private readonly ?int $maxConsecutiveSkips = null,
    ) {
    }

    public function renewalToSkip(SubscriptionInterface $subscription): ?SubscriptionCycleInterface
    {
        if (SubscriptionInterface::STATE_ACTIVE !== $subscription->getState()) {
            return null;
        }

        $openCycle = $this->scheduler->findOpenCycle($subscription);
        if (
            null === $openCycle ||
            null !== $openCycle->getOrder() ||
            !\in_array($openCycle->getState(), self::SKIPPABLE_CYCLE_STATES, true)
        ) {
            return null;
        }

        if (null !== $this->maxConsecutiveSkips && $this->skipsInARowBefore($openCycle) >= $this->maxConsecutiveSkips) {
            return null;
        }

        return $openCycle;
    }

    public function canSkip(SubscriptionInterface $subscription): bool
    {
        return null !== $this->renewalToSkip($subscription);
    }

    public function skip(SubscriptionInterface $subscription): SubscriptionCycleInterface
    {
        $cycle = $this->renewalToSkip($subscription);
        Assert::notNull($cycle, 'Only the open cycle of an active subscription can be skipped, before its order is placed and within the renewals a customer may skip in a row.');

        // Set before the transition is applied, so its listeners tell a skip from any other cancellation.
        $cycle->setSkipped(true);
        $this->stateMachine->apply($cycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_CANCEL);
        $this->scheduler->scheduleNext($subscription);

        // Publishing never stops a skip: a subscription or cycle not stored yet has no identifier to carry.
        $subscriptionId = $subscription->getId();
        $cycleId = $cycle->getId();
        $scheduledAt = $cycle->getScheduledAt();
        $nextScheduledAt = $this->scheduler->findOpenCycle($subscription)?->getScheduledAt();
        if (null !== $subscriptionId && null !== $cycleId && null !== $scheduledAt && null !== $nextScheduledAt) {
            $this->eventPublisher->publish(new RenewalSkipped($subscriptionId, $cycleId, $cycle->getNumber(), $scheduledAt, $nextScheduledAt));
        }

        return $cycle;
    }

    private function skipsInARowBefore(SubscriptionCycleInterface $openCycle): int
    {
        $subscription = $openCycle->getSubscription();
        Assert::notNull($subscription);

        $earlier = [];
        foreach ($subscription->getCycles() as $cycle) {
            if ($cycle->getNumber() < $openCycle->getNumber()) {
                $earlier[$cycle->getNumber()] = $cycle;
            }
        }
        krsort($earlier);

        $skips = 0;
        foreach ($earlier as $cycle) {
            if (SubscriptionCycleInterface::STATE_CANCELLED !== $cycle->getState() || !$cycle->isSkipped()) {
                break;
            }
            ++$skips;
        }

        return $skips;
    }
}
