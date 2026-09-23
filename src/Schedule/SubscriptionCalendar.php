<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Schedule;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use Webmozart\Assert\Assert;

/** Pure date arithmetic: "now" is never read here, it comes from the clock of whoever anchors the schedule. */
final class SubscriptionCalendar implements SubscriptionCalendarInterface
{
    public function dateOfCycle(SubscriptionInterface $subscription, int $number): \DateTimeImmutable
    {
        $anchorAt = $subscription->getScheduleAnchorAt();
        Assert::notNull($anchorAt, 'A subscription has no schedule until it is activated.');
        $intervals = $number - $subscription->getScheduleAnchorCycle();
        Assert::greaterThanEq($intervals, 0, 'The cycles before the schedule anchor keep the dates they were given.');
        $count = $intervals * $subscription->getBillingIntervalCount();

        return match ($subscription->getBillingIntervalUnit()) {
            SubscriptionIntervalUnit::Day => $anchorAt->add(new \DateInterval(\sprintf('P%dD', $count))),
            SubscriptionIntervalUnit::Week => $anchorAt->add(new \DateInterval(\sprintf('P%dD', 7 * $count))),
            SubscriptionIntervalUnit::Month => self::addMonths($anchorAt, $count),
            SubscriptionIntervalUnit::Year => self::addMonths($anchorAt, 12 * $count),
        };
    }

    /** On the same day of the month, or on the last day of a month too short for it, at the same time. */
    private static function addMonths(\DateTimeImmutable $date, int $months): \DateTimeImmutable
    {
        $monthIndex = 12 * (int) $date->format('Y') + (int) $date->format('n') - 1 + $months;
        $year = intdiv($monthIndex, 12);
        $month = $monthIndex % 12 + 1;
        $lastDay = (int) $date->setDate($year, $month, 1)->format('t');

        return $date->setDate($year, $month, min((int) $date->format('j'), $lastDay));
    }
}
