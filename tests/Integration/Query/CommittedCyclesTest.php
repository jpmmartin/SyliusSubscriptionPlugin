<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Query;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Query\CommittedCycle;
use JpmMartin\SyliusSubscriptionPlugin\Query\CommittedCyclesQuery;
use JpmMartin\SyliusSubscriptionPlugin\Query\CommittedCyclesQueryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\ConfigurableMissedCyclePolicy;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\MissedCycles;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionCalendarInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionSchedulerInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Psr\Clock\ClockInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;

/**
 * Two monthly Coffee subscriptions of one unit, activated on 1 and 10 January, asked about on
 * 15 January. A Tea subscription and a cancelled Coffee one are there too, and must not count.
 */
final class CommittedCyclesTest extends LifecycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedCoffeeOrder());
        $teaOrder = $this->cart();
        $this->addLine($teaOrder, $this->tea, 1, $this->teaEveryTwoWeeks);
        $this->placeWithConsent($teaOrder);
        $this->pay($teaOrder);

        $this->itIsNow('2027-01-10 09:00');
        $this->pay($this->placedCoffeeOrder());

        $this->itIsNow('2027-01-12 09:00');
        $cancelledOrder = $this->placedCoffeeOrder();
        $this->pay($cancelledOrder);
        $subscriptions = $this->storedSubscriptions();
        $this->apply(end($subscriptions), SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_CANCEL);
        $this->entityManager()->flush();

        $this->itIsNow('2027-01-15 09:00');
    }

    public function testThreeMonthsOfTwoMonthlySubscriptionsOfOneUnitAreSixCycles(): void
    {
        self::assertSame(
            [['2027-02-01', 1], ['2027-02-10', 1], ['2027-03-01', 1], ['2027-03-10', 1], ['2027-04-01', 1], ['2027-04-10', 1]],
            $this->committedCoffee('P3M'),
        );
    }

    public function testNoCycleIsCommittedPastTheCyclesThePlanStillAllows(): void
    {
        // Each subscription has paid its first cycle, so a plan of three cycles has two left.
        $this->coffeeMonthly->setMaxCycles(3);
        $this->entityManager()->flush();

        self::assertSame(
            [['2027-02-01', 1], ['2027-02-10', 1], ['2027-03-01', 1], ['2027-03-10', 1]],
            $this->committedCoffee('P3M'),
        );
    }

    public function testEachCycleCarriesTheQuantityOfItsSubscription(): void
    {
        $this->itIsNow('2027-01-20 09:00');
        $order = $this->cart();
        $this->addLine($order, $this->coffee, 3, $this->coffeeMonthly);
        $this->placeWithConsent($order);
        $this->pay($order);

        self::assertSame(
            [['2027-02-01', 1], ['2027-02-10', 1], ['2027-02-20', 3]],
            $this->committedCoffee('P1M'),
        );
    }

    public function testABatchCommitsTheQuantityOfItsItemOfTheVariantOnly(): void
    {
        $this->itIsNow('2027-01-20 09:00');
        $order = $this->cart();
        $this->addLine($order, $this->coffee, 2, $this->coffeeMonthly);
        $this->addLine($order, $this->tea, 1, $this->teaMonthly);
        $this->placeWithConsent($order);
        $this->pay($order);

        self::assertSame(
            [['2027-02-01', 1], ['2027-02-10', 1], ['2027-02-20', 2]],
            $this->committedCoffee('P1M'),
        );
    }

    public function testAnItemCommitsNoCyclePastItsOwnPlansMaximumWhateverTheOtherItemsOfItsBatch(): void
    {
        $this->itIsNow('2027-01-20 09:00');
        $order = $this->cart();
        $this->addLine($order, $this->coffee, 2, $this->coffeeMonthly);
        $this->addLine($order, $this->tea, 1, $this->teaMonthly);
        $this->placeWithConsent($order);
        $this->pay($order);
        $this->coffeeMonthly->setMaxCycles(2);
        $this->entityManager()->flush();

        self::assertSame(
            [['2027-02-01', 1], ['2027-02-10', 1], ['2027-02-20', 2]],
            $this->committedCoffee('P3M'),
            'Every Coffee item has one cycle left; the batch\'s Tea renews on without it.',
        );
    }

    public function testAnItemRepeatedWithAFrequencyCommitsNoCyclePastTheFrequencysMaximum(): void
    {
        $this->itIsNow('2027-01-20 09:00');
        $monthly = $this->storeFrequency('MONTHLY', 1, SubscriptionIntervalUnit::Month, 0, $this->coffee);
        $monthly->setMaxCycles(3);
        $order = $this->cart();
        $this->addLine($order, $this->coffee, 4, null);
        $this->repeat($order, $monthly);
        $this->placeWithConsent($order);
        $this->pay($order);

        self::assertSame(
            [['2027-02-01', 1], ['2027-02-10', 1], ['2027-02-20', 4], ['2027-03-01', 1], ['2027-03-10', 1], ['2027-03-20', 4], ['2027-04-01', 1], ['2027-04-10', 1]],
            $this->committedCoffee('P3M'),
            'The repeated Coffee paid its first of three cycles, so two are left.',
        );
    }

    public function testASubscriptionBehindItsCalendarCommitsItsOpenCycleAndThenTheDatesToComeOnly(): void
    {
        // The open cycles, of 1 and 10 February, are charged when the command runs again; the dates from
        // March to 10 June have come and are skipped.
        $this->itIsNow('2027-06-10 09:00');

        self::assertSame(
            [['2027-02-01', 1], ['2027-02-10', 1], ['2027-07-01', 1], ['2027-07-10', 1]],
            $this->committedCoffee('P1M'),
        );
    }

    public function testWhenTheLateCycleIsSkippedTooItIsNotCommitted(): void
    {
        $this->itIsNow('2027-06-10 09:00');

        self::assertSame(
            [['2027-07-01', 1], ['2027-07-10', 1]],
            $this->committedCoffee('P1M', MissedCycles::SkipLate),
        );
    }

    public function testWhenEachMissedDateIsChargedEachIsCommitted(): void
    {
        $this->itIsNow('2027-06-10 09:00');

        self::assertSame(
            [
                ['2027-02-01', 1], ['2027-02-10', 1], ['2027-03-01', 1], ['2027-03-10', 1], ['2027-04-01', 1], ['2027-04-10', 1],
                ['2027-05-01', 1], ['2027-05-10', 1], ['2027-06-01', 1], ['2027-06-10', 1], ['2027-07-01', 1], ['2027-07-10', 1],
            ],
            $this->committedCoffee('P1M', MissedCycles::Charge),
        );
    }

    /** @return list<array{string, int}> the date and quantity of each committed cycle of Coffee */
    private function committedCoffee(string $horizon, ?MissedCycles $missedCycles = null): array
    {
        $query = null === $missedCycles ? self::getContainer()->get(CommittedCyclesQueryInterface::class) : $this->queryWith($missedCycles);
        self::assertInstanceOf(CommittedCyclesQueryInterface::class, $query);
        $this->entityManager()->clear();
        $coffee = $this->entityManager()->find($this->coffee::class, $this->coffee->getId());
        self::assertNotNull($coffee);

        return array_map(
            static fn (CommittedCycle $cycle): array => [$cycle->date->format('Y-m-d'), $cycle->quantity],
            $query->forProductVariant($coffee, new \DateInterval($horizon)),
        );
    }

    private function queryWith(MissedCycles $missedCycles): CommittedCyclesQuery
    {
        $container = self::getContainer();
        /** @var SubscriptionRepositoryInterface<\JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface> $subscriptions */
        $subscriptions = $container->get('jpm_martin_sylius_subscription.repository.subscription');
        /** @var SubscriptionSchedulerInterface $scheduler */
        $scheduler = $container->get('jpm_martin_sylius_subscription.schedule.scheduler');
        /** @var SubscriptionCalendarInterface $calendar */
        $calendar = $container->get('jpm_martin_sylius_subscription.schedule.calendar');
        /** @var ClockInterface $clock */
        $clock = $container->get('clock');

        return new CommittedCyclesQuery($subscriptions, $scheduler, $calendar, $clock, new ConfigurableMissedCyclePolicy($calendar, $missedCycles));
    }
}
