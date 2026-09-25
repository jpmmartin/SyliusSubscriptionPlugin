<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\EventListener\Workflow\Subscription;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionCalendarInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionSchedulerInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Psr\Clock\ClockInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Webmozart\Assert\Assert;

/**
 * A reactivated or resumed subscription renews on the first date of its own calendar after that day.
 * Only a reactivation starts a new run of failures: pausing changes nothing about the payment method,
 * so a resumed subscription keeps its failed cycles in a row. The anchor date stays, so the calendar keeps its day of the month;
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

        if (SubscriptionTransitions::TRANSITION_REACTIVATE === $event->getTransition()?->getName()) {
            $subscription->setConsecutiveFailedCycles(0);
            $subscription->setSuspendedForFailedCycles(false);
        }

        $now = $this->clock->now();
        $nextNumber = 1;
        foreach ($subscription->getCycles() as $cycle) {
            $nextNumber = max($nextNumber, $cycle->getNumber() + 1);
        }

        $subscription->setScheduleAnchorCycle(
            $subscription->getScheduleAnchorCycle() - $this->calendar->countDatesUntil($subscription, $nextNumber, $now),
        );
        // The cycle cancelled by the pause or the suspension keeps its number even when its date is still
        // to come: that date is then the first one after today, and the next cycle takes it.
        while (
            $nextNumber - 1 >= $subscription->getScheduleAnchorCycle() &&
            $this->calendar->dateOfCycle($subscription, $nextNumber - 1) > $now
        ) {
            $subscription->setScheduleAnchorCycle($subscription->getScheduleAnchorCycle() + 1);
        }

        $this->scheduler->scheduleNext($subscription);
    }
}
