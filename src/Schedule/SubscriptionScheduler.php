<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Schedule;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionCycleTransitions;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Webmozart\Assert\Assert;

/**
 * The next cycle's number follows the last one, whatever became of it, and its date comes from the
 * calendar, so a failed or late cycle never moves the ones after it. When that date has already come,
 * the missed cycle policy says how many of the dates that came to skip: a skipped date creates no
 * cycle, it only moves the cycle number the anchor stands for, as reactivating does.
 */
final class SubscriptionScheduler implements SubscriptionSchedulerInterface
{
    private const OPEN_STATES = [
        SubscriptionCycleInterface::STATE_SCHEDULED,
        SubscriptionCycleInterface::STATE_ON_HOLD,
        SubscriptionCycleInterface::STATE_AWAITING_PAYMENT,
    ];

    /** @param FactoryInterface<SubscriptionCycleInterface> $cycleFactory */
    public function __construct(
        private readonly FactoryInterface $cycleFactory,
        private readonly SubscriptionCalendarInterface $calendar,
        private readonly StateMachineInterface $stateMachine,
        private readonly MissedCyclePolicyInterface $missedCyclePolicy,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function scheduleNext(SubscriptionInterface $subscription): void
    {
        // A subscription that stopped meanwhile schedules nothing more.
        if (SubscriptionInterface::STATE_ACTIVE !== $subscription->getState()) {
            return;
        }

        $openCycle = $this->findOpenCycle($subscription);

        // The maximum counts each item's paid cycles: one that failed or skipped it does not use up its plan.
        $renewable = $subscription->getItems()->exists(
            static fn (int|string $key, SubscriptionItemInterface $item): bool => $item->isRenewable(),
        );
        if (!$renewable) {
            // A manual retry paid late can use up the last item after the next cycle was scheduled.
            if (null !== $openCycle && $this->stateMachine->can($openCycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_CANCEL)) {
                $this->stateMachine->apply($openCycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_CANCEL);
            }
            $this->stateMachine->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_COMPLETE);

            return;
        }

        if (null !== $openCycle) {
            return;
        }

        $number = 1;
        foreach ($subscription->getCycles() as $cycle) {
            $number = max($number, $cycle->getNumber() + 1);
        }

        $skipped = $this->skipMissedDates($subscription, $number);

        $next = $this->cycleFactory->createNew();
        Assert::isInstanceOf($next, SubscriptionCycleInterface::class);
        $next->setNumber($number);
        $scheduledAt = $this->calendar->dateOfCycle($subscription, $number);
        $next->setScheduledAt($scheduledAt);
        $subscription->addCycle($next);

        if (0 < $skipped) {
            $this->logger->warning('Subscription {subscription} skipped {skipped} date(s) of its calendar that had already come; its next cycle is on {date}.', [
                'subscription' => $subscription->getId(),
                'skipped' => $skipped,
                'date' => $scheduledAt->format(\DateTimeInterface::ATOM),
            ]);
        }
    }

    /** @return int how many dates were skipped */
    private function skipMissedDates(SubscriptionInterface $subscription, int $number): int
    {
        $now = $this->clock->now();
        $skipped = $this->missedCyclePolicy->datesToSkip($subscription, $number, $now);
        if (0 === $skipped) {
            return 0;
        }

        $come = $this->calendar->countDatesUntil($subscription, $number, $now);
        if ($skipped < 0 || $skipped > $come) {
            throw new \LogicException(\sprintf(
                'The missed cycle policy may skip from 0 to the %d date(s) of subscription %s that have come, not %d.',
                $come,
                (string) $subscription->getId(),
                $skipped,
            ));
        }
        $subscription->setScheduleAnchorCycle($subscription->getScheduleAnchorCycle() - $skipped);

        return $skipped;
    }

    public function findOpenCycle(SubscriptionInterface $subscription): ?SubscriptionCycleInterface
    {
        foreach ($subscription->getCycles() as $cycle) {
            if (!$cycle->isManualRetry() && \in_array($cycle->getState(), self::OPEN_STATES, true)) {
                return $cycle;
            }
        }

        return null;
    }
}
