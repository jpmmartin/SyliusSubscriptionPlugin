<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\EventListener\Workflow\Subscription;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionCalendarInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionSchedulerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Webmozart\Assert\Assert;

/**
 * A reactivated subscription starts a new run of failures and renews on the first date of its own
 * calendar after the reactivation. The anchor date stays, so the calendar keeps its day of the month;
 * only the cycle number the anchor stands for moves, so the next cycle falls on that date.
 */
final class ResumeScheduleListener
{
    public function __construct(
        private readonly SubscriptionCalendarInterface $calendar,
        private readonly SubscriptionSchedulerInterface $scheduler,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $subscription = $event->getSubject();
        Assert::isInstanceOf($subscription, SubscriptionInterface::class);

        $subscription->setConsecutiveFailedCycles(0);

        $now = $this->clock->now();
        $nextNumber = 1;
        foreach ($subscription->getCycles() as $cycle) {
            $nextNumber = max($nextNumber, $cycle->getNumber() + 1);
        }

        $subscription->setScheduleAnchorCycle(
            $subscription->getScheduleAnchorCycle() - $this->calendar->countDatesUntil($subscription, $nextNumber, $now),
        );

        $this->scheduler->scheduleNext($subscription);
    }
}
