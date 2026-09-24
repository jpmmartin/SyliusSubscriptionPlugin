<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Cycle;

use JpmMartin\SyliusSubscriptionPlugin\CommandHandler\ProcessSubscriptionCycleHandler;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use JpmMartin\SyliusSubscriptionPlugin\Factory\SubscriptionPlanFactoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Gate\GateDecision;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\ConfigurableMissedCyclePolicy;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\MissedCyclePolicyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\MissedCycles;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionCalendarInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionScheduler;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionCycleTransitions;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Gate\ScriptedCycleGate;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

/**
 * Dates of the calendar that passed before a cycle could be scheduled on them: by default they are
 * skipped, so a command that stopped for months charges a subscription once when it runs again.
 */
final class MissedCycleDatesTest extends LifecycleTestCase
{
    public function testACommandStoppedForThreeMonthsChargesTheOverdueCycleOnceAndSchedulesTheNextOnTheFirstDateToCome(): void
    {
        $this->itIsNow('2027-02-01 09:00');
        $this->pay($this->placedCoffeeOrder());

        // Due on 1 March, but the store's scheduler does not run again until 10 June, then every hour.
        $this->itIsNow('2027-06-10 09:00');
        $this->runTheCycleCommand();
        $this->itIsNow('2027-06-10 10:00');
        $this->runTheCycleCommand();
        $this->itIsNow('2027-06-10 11:00');
        $this->runTheCycleCommand();
        $this->itIsNow('2027-06-10 12:00');
        $this->runTheCycleCommand();

        self::assertSame(['capture'], $this->scriptedGateway()->requests(), 'One charge, not one per missed date.');
        self::assertSame(2, $this->countOrders(), 'The initial order and one renewal.');
        $cycles = $this->storedCycles($this->subscription());
        self::assertSame([1, 2, 3], array_map(static fn (SubscriptionCycleInterface $cycle): int => $cycle->getNumber(), $cycles), 'No cycle for April, May or June.');
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $cycles[1]->getState());
        self::assertSame('2027-03-01 09:00', $cycles[1]->getScheduledAt()?->format('Y-m-d H:i'));
        self::assertSame(SubscriptionCycleInterface::STATE_SCHEDULED, $cycles[2]->getState());
        self::assertSame('2027-07-01 09:00', $cycles[2]->getScheduledAt()?->format('Y-m-d H:i'), 'The calendar keeps its day and time.');
    }

    public function testAWeeklyCycleThatRunsOutOfRetriesSchedulesTheNextOnTheWeekAfterTheDateThatPassedMeanwhile(): void
    {
        $weekly = $this->coffeeWeeklyPlan();
        $this->itIsNow('2027-02-22 09:00');
        $order = $this->cart();
        $this->addLine($order, $this->coffee, 1, $weekly);
        $this->placeWithConsent($order);
        $this->pay($order);

        // Due on 1 March at 09:00; declined then, and on each retry (days 1, 3 and 7), each run at 09:30.
        foreach (['2027-03-01 09:30', '2027-03-02 09:30', '2027-03-04 09:30', '2027-03-08 09:30'] as $dateTime) {
            $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
            $this->itIsNow($dateTime);
            $this->runTheCycleCommand();
        }

        [, $second, $third] = $this->storedCycles($this->subscription());
        self::assertSame(SubscriptionCycleInterface::STATE_FAILED, $second->getState());
        self::assertSame(3, $third->getNumber());
        self::assertSame(SubscriptionCycleInterface::STATE_SCHEDULED, $third->getState());
        self::assertSame('2027-03-15 09:00', $third->getScheduledAt()?->format('Y-m-d H:i'), 'The date of 8 March passed while the cycle was retried.');
    }

    public function testSkippedDatesDoNotUseUpAPlan(): void
    {
        $this->coffeeMonthly->setMaxCycles(6);
        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedCoffeeOrder());
        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();
        self::assertSame(2, $this->onlyItemOf($this->subscription())->getPaidCycles());

        // Stopped from 1 March to 10 June: April, May and June are skipped.
        $this->itIsNow('2027-06-10 09:00');
        $this->runTheCycleCommand();
        $this->itIsNow('2027-06-10 10:00');
        $this->runTheCycleCommand();
        self::assertSame(3, $this->onlyItemOf($this->subscription())->getPaidCycles());

        foreach (['2027-07-01 09:00', '2027-08-01 09:00', '2027-09-01 09:00'] as $dateTime) {
            $this->itIsNow($dateTime);
            $this->runTheCycleCommand();
        }

        $subscription = $this->subscription();
        self::assertSame(6, $this->onlyItemOf($subscription)->getPaidCycles(), 'Four more cycles after the skipped dates: 1 March, July, August and September.');
        self::assertSame(SubscriptionInterface::STATE_COMPLETED, $subscription->getState());
    }

    public function testAStoreThatChoosesToChargeEachMissedDateGetsOneChargePerRun(): void
    {
        $this->useMissedCycles(MissedCycles::Charge);
        $this->itIsNow('2027-02-01 09:00');
        $this->pay($this->placedCoffeeOrder());

        foreach (['2027-06-10 09:00', '2027-06-10 10:00', '2027-06-10 11:00', '2027-06-10 12:00'] as $run => $dateTime) {
            $this->itIsNow($dateTime);
            $this->runTheCycleCommand();
            self::assertCount($run + 1, $this->scriptedGateway()->requests(), 'One missed date charged per run.');
        }
        $this->itIsNow('2027-06-10 13:00');
        $this->runTheCycleCommand();

        self::assertCount(4, $this->scriptedGateway()->requests(), 'Nothing more once the dates that came are charged.');
        $cycles = $this->storedCycles($this->subscription());
        self::assertSame(
            ['2027-03-01 09:00 paid', '2027-04-01 09:00 paid', '2027-05-01 09:00 paid', '2027-06-01 09:00 paid', '2027-07-01 09:00 scheduled'],
            array_map(static fn (SubscriptionCycleInterface $cycle): string => $cycle->getScheduledAt()?->format('Y-m-d H:i') . ' ' . $cycle->getState(), \array_slice($cycles, 1)),
        );
    }

    public function testAStoreThatChoosesToSkipTheLateCycleTooCancelsItWithoutAnOrderOrAFailure(): void
    {
        $this->useMissedCycles(MissedCycles::SkipLate);
        $this->itIsNow('2027-02-01 09:00');
        $this->pay($this->placedCoffeeOrder());

        $this->itIsNow('2027-06-10 09:00');
        $this->runTheCycleCommand();
        $this->itIsNow('2027-06-10 10:00');
        $this->runTheCycleCommand();

        self::assertSame([], $this->scriptedGateway()->requests());
        self::assertSame(1, $this->countOrders(), 'Only the initial order.');
        $subscription = $this->subscription();
        [, $late, $next] = $this->storedCycles($subscription);
        self::assertSame(SubscriptionCycleInterface::STATE_CANCELLED, $late->getState());
        self::assertSame(ProcessSubscriptionCycleHandler::MISSED, $late->getCancellationReason());
        self::assertNull($late->getOrder());
        self::assertCount(0, $late->getAttempts());
        self::assertSame(0, $subscription->getConsecutiveFailedCycles(), 'A skipped cycle is not a failed one.');
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState());
        self::assertSame(SubscriptionCycleInterface::STATE_SCHEDULED, $next->getState());
        self::assertSame('2027-07-01 09:00', $next->getScheduledAt()?->format('Y-m-d H:i'));
    }

    public function testACycleHeldByAGateIsNotSkippedWhileItWaits(): void
    {
        $this->useMissedCycles(MissedCycles::SkipLate);
        $gate = self::getContainer()->get('jpm_martin_sylius_subscription.test.cycle_gate');
        self::assertInstanceOf(ScriptedCycleGate::class, $gate);
        $this->itIsNow('2027-02-01 09:00');
        $this->pay($this->placedCoffeeOrder());

        // Held on 1 March until 20 April at the latest; 1 April, the next date, comes meanwhile.
        $gate->decide(GateDecision::wait(new \DateTimeImmutable('2027-04-20 09:00'), 'Waiting for the prescriber.'));
        $this->itIsNow('2027-03-01 09:00');
        $this->runTheCycleCommand();
        $this->itIsNow('2027-04-10 09:00');
        $this->runTheCycleCommand();
        [, $held] = $this->storedCycles($this->subscription());
        self::assertSame(SubscriptionCycleInterface::STATE_ON_HOLD, $held->getState(), 'Its wait is deliberate.');

        $gate->decide(GateDecision::pass());
        $this->itIsNow('2027-04-12 09:00');
        $this->runTheCycleCommand();

        [, $held, $next] = $this->storedCycles($this->subscription());
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $held->getState());
        self::assertSame('2027-05-01 09:00', $next->getScheduledAt()?->format('Y-m-d H:i'), 'The date of 1 April passed while it waited.');
    }

    public function testTheCommandSaysHowManyDueCyclesAreMoreThanOneIntervalLate(): void
    {
        // One subscription due on 1 March, the other on 10 June.
        $this->itIsNow('2027-02-01 09:00');
        $this->pay($this->placedCoffeeOrder());
        $this->itIsNow('2027-05-10 09:00');
        $this->pay($this->placedCoffeeOrder());

        $this->itIsNow('2027-06-10 09:00');
        $display = $this->displayOf($this->runTheCycleCommand());

        self::assertStringContainsString('1 due subscription cycle(s) are more than one interval late', $display);
        self::assertStringContainsString('(missed_cycles: skip)', $display);
        self::assertStringContainsString('2 due subscription cycle(s) processed, 0 renewal(s) announced, 0 failed.', $display);
    }

    public function testTheCommandSaysNothingAboutLateCyclesWhenNoneIs(): void
    {
        $this->itIsNow('2027-02-01 09:00');
        $this->pay($this->placedCoffeeOrder());

        $this->itIsNow('2027-03-04 09:00');
        $display = $this->displayOf($this->runTheCycleCommand());

        self::assertStringNotContainsString('late', $display, 'Three days late is less than an interval.');
        self::assertStringContainsString('1 due subscription cycle(s) processed, 0 renewal(s) announced, 0 failed.', $display);
    }

    public function testAPolicyThatSkipsDatesThatHaveNotComeIsRefused(): void
    {
        $this->itIsNow('2027-02-01 09:00');
        $this->pay($this->placedCoffeeOrder());
        $subscription = $this->subscription();
        [, $open] = $this->storedCycles($subscription);
        $this->apply($open, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_CANCEL);
        // Cycle 3 falls on 1 April: that date and 1 May have come. A policy asks to skip ten.
        $this->itIsNow('2027-05-10 09:00');

        $container = self::getContainer();
        /** @var FactoryInterface<SubscriptionCycleInterface> $cycleFactory */
        $cycleFactory = $container->get('jpm_martin_sylius_subscription.factory.subscription_cycle');
        /** @var SubscriptionCalendarInterface $calendar */
        $calendar = $container->get('jpm_martin_sylius_subscription.schedule.calendar');
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = $container->get('sylius_abstraction.state_machine');
        /** @var ClockInterface $clock */
        $clock = $container->get('clock');
        $scheduler = new SubscriptionScheduler($cycleFactory, $calendar, $stateMachine, new class() implements MissedCyclePolicyInterface {
            public function datesToSkip(SubscriptionInterface $subscription, int $nextNumber, \DateTimeImmutable $now): int
            {
                return 10;
            }

            public function isStillDue(SubscriptionCycleInterface $cycle, \DateTimeImmutable $now): bool
            {
                return true;
            }
        }, $clock);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/may skip from 0 to the 2 date\(s\) of subscription \d+ that have come, not 10/');

        $scheduler->scheduleNext($subscription);
    }

    public function testEachSkipIsLoggedWithTheSubscriptionHowManyDatesAndTheNextDate(): void
    {
        $this->itIsNow('2027-02-01 09:00');
        $this->pay($this->placedCoffeeOrder());
        $subscription = $this->subscription();
        [, $open] = $this->storedCycles($subscription);
        $this->apply($open, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_CANCEL);
        $this->itIsNow('2027-05-10 09:00');

        $logger = new class() extends AbstractLogger {
            /** @var list<array{string, string, array<mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
        $container = self::getContainer();
        /** @var FactoryInterface<SubscriptionCycleInterface> $cycleFactory */
        $cycleFactory = $container->get('jpm_martin_sylius_subscription.factory.subscription_cycle');
        /** @var SubscriptionCalendarInterface $calendar */
        $calendar = $container->get('jpm_martin_sylius_subscription.schedule.calendar');
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = $container->get('sylius_abstraction.state_machine');
        /** @var ClockInterface $clock */
        $clock = $container->get('clock');
        $scheduler = new SubscriptionScheduler($cycleFactory, $calendar, $stateMachine, new ConfigurableMissedCyclePolicy($calendar, MissedCycles::Skip), $clock, $logger);

        $scheduler->scheduleNext($subscription);

        self::assertCount(1, $logger->records);
        [$level, , $context] = $logger->records[0];
        self::assertSame('warning', $level);
        self::assertSame(['subscription' => $subscription->getId(), 'skipped' => 2, 'date' => '2027-06-01T09:00:00+00:00'], $context, 'April and May skipped; next on 1 June.');
    }

    /** What the command printed, on one line whatever the width of the console. */
    private function displayOf(\Symfony\Component\Console\Tester\CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /** Before anything is scheduled, so the scheduler is built with it. */
    private function useMissedCycles(MissedCycles $mode): void
    {
        /** @var SubscriptionCalendarInterface $calendar */
        $calendar = self::getContainer()->get('jpm_martin_sylius_subscription.schedule.calendar');
        self::getContainer()->set('jpm_martin_sylius_subscription.schedule.missed_cycle_policy', new ConfigurableMissedCyclePolicy($calendar, $mode));
    }

    private function coffeeWeeklyPlan(): SubscriptionPlanInterface
    {
        /** @var SubscriptionPlanFactoryInterface $factory */
        $factory = self::getContainer()->get('jpm_martin_sylius_subscription.factory.subscription_plan');
        $plan = $factory->createForVariant($this->coffee);
        $plan->setCode('COFFEE_WEEKLY');
        $plan->setName('COFFEE_WEEKLY');
        $plan->setIntervalCount(1);
        $plan->setIntervalUnit(SubscriptionIntervalUnit::Week);
        $plan->setDiscountPercentage(0);
        $this->entityManager()->persist($plan);
        $this->entityManager()->flush();

        return $plan;
    }

    private function subscription(): SubscriptionInterface
    {
        $subscriptions = $this->storedSubscriptions();
        self::assertCount(1, $subscriptions);

        return $subscriptions[0];
    }

    private function countOrders(): int
    {
        /** @var RepositoryInterface<OrderInterface> $repository */
        $repository = self::getContainer()->get('sylius.repository.order');

        return \count($repository->findBy(['checkoutState' => 'completed']));
    }
}
