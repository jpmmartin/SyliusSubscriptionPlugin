<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Schedule;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use Webmozart\Assert\Assert;

/** The plugin's missed cycle policy, doing what the "missed_cycles" option says. */
final class ConfigurableMissedCyclePolicy implements MissedCyclePolicyInterface
{
    private readonly MissedCycles $mode;

    public function __construct(
        private readonly SubscriptionCalendarInterface $calendar,
        MissedCycles|string $mode,
    ) {
        $this->mode = $mode instanceof MissedCycles ? $mode : MissedCycles::from($mode);
    }

    public function datesToSkip(SubscriptionInterface $subscription, int $nextNumber, \DateTimeImmutable $now): int
    {
        if (MissedCycles::Charge === $this->mode) {
            return 0;
        }

        return $this->calendar->countDatesUntil($subscription, $nextNumber, $now);
    }

    public function isStillDue(SubscriptionCycleInterface $cycle, \DateTimeImmutable $now): bool
    {
        if (MissedCycles::SkipLate !== $this->mode) {
            return true;
        }

        $subscription = $cycle->getSubscription();
        Assert::notNull($subscription);

        // Late by more than its interval: the date of the cycle after it has come too.
        return $this->calendar->dateOfCycle($subscription, $cycle->getNumber() + 1) > $now;
    }
}
