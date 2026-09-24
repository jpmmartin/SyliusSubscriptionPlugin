<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Unit\Schedule;

use JpmMartin\SyliusSubscriptionPlugin\Entity\Subscription;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycle;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\ConfigurableMissedCyclePolicy;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\MissedCycles;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionCalendar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A monthly subscription activated on 1 February, whose cycle 2 is due on 1 March, looked at on
 * 10 June: the dates of 1 March, April, May and June have come.
 */
final class ConfigurableMissedCyclePolicyTest extends TestCase
{
    /** @return iterable<string, array{MissedCycles, int, bool}> the dates skipped before cycle 3, and whether cycle 2 is still charged */
    public static function modes(): iterable
    {
        yield 'skip' => [MissedCycles::Skip, 3, true];
        yield 'charge' => [MissedCycles::Charge, 0, true];
        yield 'skip_late' => [MissedCycles::SkipLate, 3, false];
    }

    #[DataProvider('modes')]
    public function testEachModeDealsWithTheDatesThatPassedAsConfigured(MissedCycles $mode, int $datesToSkip, bool $stillDue): void
    {
        $policy = new ConfigurableMissedCyclePolicy(new SubscriptionCalendar(), $mode);
        $cycle = $this->dueCycle();
        $now = new \DateTimeImmutable('2027-06-10 09:00');

        // After cycle 2, cycle 3 would fall on 1 April: April, May and June have come.
        self::assertSame($datesToSkip, $policy->datesToSkip($this->subscriptionOf($cycle), 3, $now));
        self::assertSame($stillDue, $policy->isStillDue($cycle, $now));
    }

    public function testNothingIsSkippedWhileTheNextDateIsStillToCome(): void
    {
        $policy = new ConfigurableMissedCyclePolicy(new SubscriptionCalendar(), 'skip_late');
        $cycle = $this->dueCycle();
        $now = new \DateTimeImmutable('2027-03-04 09:00');

        self::assertSame(0, $policy->datesToSkip($this->subscriptionOf($cycle), 3, $now));
        self::assertTrue($policy->isStillDue($cycle, $now), 'Three days late is less than an interval.');
    }

    public function testTheModeIsTakenFromTheOptionsValue(): void
    {
        $policy = new ConfigurableMissedCyclePolicy(new SubscriptionCalendar(), 'charge');
        $cycle = $this->dueCycle();

        self::assertSame(0, $policy->datesToSkip($this->subscriptionOf($cycle), 3, new \DateTimeImmutable('2027-06-10 09:00')));
    }

    private function dueCycle(): SubscriptionCycleInterface
    {
        $subscription = new Subscription();
        $subscription->setBillingIntervalCount(1);
        $subscription->setBillingIntervalUnit(SubscriptionIntervalUnit::Month);
        $subscription->setScheduleAnchorAt(new \DateTimeImmutable('2027-02-01 09:00'));
        $subscription->setScheduleAnchorCycle(1);

        $cycle = new SubscriptionCycle();
        $cycle->setNumber(2);
        $cycle->setScheduledAt(new \DateTimeImmutable('2027-03-01 09:00'));
        $subscription->addCycle($cycle);

        return $cycle;
    }

    private function subscriptionOf(SubscriptionCycleInterface $cycle): Subscription
    {
        $subscription = $cycle->getSubscription();
        self::assertInstanceOf(Subscription::class, $subscription);

        return $subscription;
    }
}
