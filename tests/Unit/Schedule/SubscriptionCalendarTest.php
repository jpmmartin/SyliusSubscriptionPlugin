<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Unit\Schedule;

use JpmMartin\SyliusSubscriptionPlugin\Entity\Subscription;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionChargeAttempt;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionChargeAttemptInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycle;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionCalendar;
use PHPUnit\Framework\TestCase;

final class SubscriptionCalendarTest extends TestCase
{
    private SubscriptionCalendar $calendar;

    protected function setUp(): void
    {
        $this->calendar = new SubscriptionCalendar();
    }

    public function testAMonthlySubscriptionActivatedOnTheThirtyFirstOfJanuaryFallsOnTheLastDayOfShorterMonths(): void
    {
        $subscription = $this->subscription('2027-01-31 10:30', 1, SubscriptionIntervalUnit::Month);

        self::assertSame(
            ['2027-01-31 10:30', '2027-02-28 10:30', '2027-03-31 10:30', '2027-04-30 10:30'],
            $this->datesOfCycles($subscription, 1, 4),
        );
    }

    public function testFebruaryOfALeapYearEndsOnItsTwentyNinth(): void
    {
        $subscription = $this->subscription('2028-01-31 10:30', 1, SubscriptionIntervalUnit::Month);

        self::assertSame('2028-02-29 10:30', $this->dateOfCycle($subscription, 2));
    }

    public function testALateChargeDoesNotMoveTheCyclesAfterIt(): void
    {
        $subscription = $this->subscription('2027-01-01 09:00', 1, SubscriptionIntervalUnit::Month);
        $third = new SubscriptionCycle();
        $third->setNumber(3);
        $third->setScheduledAt(new \DateTimeImmutable('2027-03-01 09:00'));
        $third->setState(SubscriptionCycleInterface::STATE_PAID);
        foreach (['2027-03-01 09:00' => SubscriptionChargeAttemptInterface::OUTCOME_DECLINED, '2027-03-02 09:00' => SubscriptionChargeAttemptInterface::OUTCOME_DECLINED, '2027-03-04 09:00' => SubscriptionChargeAttemptInterface::OUTCOME_APPROVED] as $attemptedAt => $outcome) {
            $attempt = new SubscriptionChargeAttempt();
            $attempt->setType(SubscriptionChargeAttemptInterface::TYPE_CHARGE);
            $attempt->setOutcome($outcome);
            $attempt->setAttemptedAt(new \DateTimeImmutable($attemptedAt));
            $third->addAttempt($attempt);
        }
        $subscription->addCycle($third);

        self::assertSame('2027-04-01 09:00', $this->dateOfCycle($subscription, 4));
    }

    public function testAPlanChangeCountsTheNewIntervalFromTheOpenCycle(): void
    {
        // Monthly since 1 January, changed to every three months while the cycle of 1 March was open.
        $subscription = $this->subscription('2027-03-01 09:00', 3, SubscriptionIntervalUnit::Month);
        $subscription->setScheduleAnchorCycle(3);

        self::assertSame(['2027-03-01 09:00', '2027-06-01 09:00', '2027-09-01 09:00'], $this->datesOfCycles($subscription, 3, 5));
    }

    public function testDaysAndWeeksAreCalendarDays(): void
    {
        self::assertSame(
            ['2027-03-20 09:00', '2027-03-30 09:00', '2027-04-09 09:00'],
            $this->datesOfCycles($this->subscription('2027-03-20 09:00', 10, SubscriptionIntervalUnit::Day), 1, 3),
        );
        self::assertSame(
            ['2027-12-20 09:00', '2028-01-03 09:00', '2028-01-17 09:00'],
            $this->datesOfCycles($this->subscription('2027-12-20 09:00', 2, SubscriptionIntervalUnit::Week), 1, 3),
        );
    }

    public function testAYearlySubscriptionFromTheTwentyNinthOfFebruaryFallsOnTheTwentyEighthInCommonYears(): void
    {
        self::assertSame(
            ['2028-02-29 09:00', '2029-02-28 09:00', '2030-02-28 09:00', '2031-02-28 09:00', '2032-02-29 09:00'],
            $this->datesOfCycles($this->subscription('2028-02-29 09:00', 1, SubscriptionIntervalUnit::Year), 1, 5),
        );
    }

    public function testNoDateHasComeWhileTheCycleIsStillToCome(): void
    {
        $subscription = $this->subscription('2027-01-01 09:00', 1, SubscriptionIntervalUnit::Month);

        self::assertSame(0, $this->calendar->countDatesUntil($subscription, 2, new \DateTimeImmutable('2027-01-31 23:59')));
    }

    public function testCountsTheMonthlyDatesThatHaveComeIncludingTheLastDayOfShorterMonths(): void
    {
        // 28 February, 31 March, 30 April and 31 May have come; 30 June has not.
        $subscription = $this->subscription('2027-01-31 10:30', 1, SubscriptionIntervalUnit::Month);

        self::assertSame(4, $this->calendar->countDatesUntil($subscription, 2, new \DateTimeImmutable('2027-06-10 09:00')));
    }

    public function testCountsWeeklyDates(): void
    {
        // 8, 15 and 22 March have come; 29 March has not.
        $subscription = $this->subscription('2027-03-01 09:00', 1, SubscriptionIntervalUnit::Week);

        self::assertSame(3, $this->calendar->countDatesUntil($subscription, 2, new \DateTimeImmutable('2027-03-28 09:00')));
    }

    public function testADateEqualToNowHasCome(): void
    {
        $subscription = $this->subscription('2027-03-01 09:00', 1, SubscriptionIntervalUnit::Week);

        self::assertSame(1, $this->calendar->countDatesUntil($subscription, 2, new \DateTimeImmutable('2027-03-08 09:00')));
        self::assertSame(0, $this->calendar->countDatesUntil($subscription, 2, new \DateTimeImmutable('2027-03-08 08:59')));
    }

    public function testASubscriptionThatWasNeverActivatedHasNoSchedule(): void
    {
        $subscription = new Subscription();
        $subscription->setBillingIntervalCount(1);
        $subscription->setBillingIntervalUnit(SubscriptionIntervalUnit::Month);

        $this->expectException(\InvalidArgumentException::class);

        $this->calendar->dateOfCycle($subscription, 2);
    }

    private function subscription(string $anchorAt, int $intervalCount, SubscriptionIntervalUnit $unit): Subscription
    {
        $subscription = new Subscription();
        $subscription->setBillingIntervalCount($intervalCount);
        $subscription->setBillingIntervalUnit($unit);
        $subscription->setScheduleAnchorAt(new \DateTimeImmutable($anchorAt));
        $subscription->setScheduleAnchorCycle(1);

        return $subscription;
    }

    private function dateOfCycle(Subscription $subscription, int $number): string
    {
        return $this->calendar->dateOfCycle($subscription, $number)->format('Y-m-d H:i');
    }

    /** @return list<string> */
    private function datesOfCycles(Subscription $subscription, int $from, int $to): array
    {
        return array_map(fn (int $number): string => $this->dateOfCycle($subscription, $number), range($from, $to));
    }
}
