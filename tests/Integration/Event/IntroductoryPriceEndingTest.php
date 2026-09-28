<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Event;

use JpmMartin\SyliusSubscriptionPlugin\Console\Command\ProcessSubscriptionCyclesCommand;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\IntroductoryPriceEnding;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalUpcoming;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionCycleRepositoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionCalendarInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Event\EventCollector;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;

/**
 * Coffee at $100.00 on its monthly plan with 10% off and an introductory discount of 50%, activated on
 * 1 January: its renewals are announced three days before the first of each month.
 */
final class IntroductoryPriceEndingTest extends LifecycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->coffeeMonthly->setIntroductoryDiscountPercentage(50);
    }

    public function testTheFirstRenewalAtTheNormalPriceIsAnnouncedWithItsTotal(): void
    {
        $this->activatedOnTheFirstOfJanuary(false);

        $this->itIsNow('2027-01-29 09:00');
        $this->runTheCycleCommand();

        [, $second] = $this->storedCycles($this->subscription());
        $subscriptionId = (int) $this->subscription()->getId();
        self::assertEquals([
            new RenewalUpcoming($subscriptionId, (int) $second->getId(), 2, new \DateTimeImmutable('2027-02-01 09:00')),
            new IntroductoryPriceEnding($subscriptionId, (int) $second->getId(), 2, new \DateTimeImmutable('2027-02-01 09:00'), 9000),
        ], $this->collector()->events());

        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();
        $this->itIsNow('2027-02-26 09:00');
        $this->runTheCycleCommand();
        self::assertCount(2, $this->collector()->events(RenewalUpcoming::class));
        self::assertCount(1, $this->collector()->events(IntroductoryPriceEnding::class), 'March is not the end of anything.');
    }

    public function testOnlyTheRenewalAfterTheLastIntroductoryCycleIsAnnouncedAsTheEnd(): void
    {
        $this->coffeeMonthly->setIntroductoryCycles(2);
        $this->activatedOnTheFirstOfJanuary(false);

        $this->itIsNow('2027-01-29 09:00');
        $this->runTheCycleCommand();
        self::assertSame([], $this->collector()->events(IntroductoryPriceEnding::class), 'February is still at the introductory price.');

        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();
        $this->itIsNow('2027-02-26 09:00');
        $this->runTheCycleCommand();

        $events = $this->collector()->events(IntroductoryPriceEnding::class);
        self::assertCount(1, $events);
        self::assertInstanceOf(IntroductoryPriceEnding::class, $events[0]);
        self::assertSame([3, 9000], [$events[0]->cycleNumber, $events[0]->renewalTotal]);
    }

    public function testTheTotalCountsEachItemAtItsPriceForThatRenewal(): void
    {
        $this->coffeeMonthly->setIntroductoryCycles(2);
        $this->teaMonthly->setIntroductoryDiscountPercentage(20);
        $this->activatedOnTheFirstOfJanuary(true);
        [$coffee, $tea] = $this->itemsOf($this->subscription());
        $coffee->setPendingPrice(9900, new \DateTimeImmutable('2027-02-01'));
        $tea->setPendingPrice(5500, new \DateTimeImmutable('2027-03-01'));
        $this->entityManager()->flush();
        $this->collector()->clear();

        $this->itIsNow('2027-01-29 09:00');
        $this->runTheCycleCommand();

        $events = $this->collector()->events(IntroductoryPriceEnding::class);
        self::assertCount(1, $events, 'Tea leaves its introductory price.');
        self::assertInstanceOf(IntroductoryPriceEnding::class, $events[0]);
        self::assertSame(10000, $events[0]->renewalTotal, 'Coffee still at $50.00 despite its increase, and Tea at its normal $50.00, whose increase applies later.');
    }

    public function testAnIncreaseThatAppliesByThenCountsInTheTotal(): void
    {
        $this->activatedOnTheFirstOfJanuary(false);
        $this->onlyItemOf($this->subscription())->setPendingPrice(9500, new \DateTimeImmutable('2027-02-01'));
        $this->entityManager()->flush();

        $this->itIsNow('2027-01-29 09:00');
        $this->runTheCycleCommand();

        $events = $this->collector()->events(IntroductoryPriceEnding::class);
        self::assertCount(1, $events);
        self::assertInstanceOf(IntroductoryPriceEnding::class, $events[0]);
        self::assertSame(9500, $events[0]->renewalTotal);
    }

    public function testNothingIsAnnouncedWhenTheStoreTurnsTheNoticeOff(): void
    {
        $this->activatedOnTheFirstOfJanuary(false);

        $this->itIsNow('2027-01-29 09:00');
        $this->entityManager()->clear();
        $container = self::getContainer();
        /** @var SubscriptionCycleRepositoryInterface<SubscriptionCycleInterface> $cycles */
        $cycles = $container->get('jpm_martin_sylius_subscription.repository.subscription_cycle');
        /** @var MessageBusInterface $commandBus */
        $commandBus = $container->get('sylius.command_bus');
        /** @var ClockInterface $clock */
        $clock = $container->get('clock');
        /** @var SubscriptionCalendarInterface $calendar */
        $calendar = $container->get('jpm_martin_sylius_subscription.schedule.calendar');
        $tester = new CommandTester(new ProcessSubscriptionCyclesCommand($cycles, $commandBus, $clock, $calendar, $this->entityManager(), 'skip', null));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([], $this->collector()->events());
    }

    private function activatedOnTheFirstOfJanuary(bool $withTea): void
    {
        $this->entityManager()->flush();
        $this->itIsNow('2027-01-01 09:00');
        $this->pay($withTea ? $this->placedBatchOrder() : $this->placedCoffeeOrder());
        $this->collector()->clear();
    }

    private function subscription(): SubscriptionInterface
    {
        $subscriptions = $this->storedSubscriptions();
        self::assertCount(1, $subscriptions);

        return $subscriptions[0];
    }

    /** @return list<SubscriptionItemInterface> in the order of the lines */
    private function itemsOf(SubscriptionInterface $subscription): array
    {
        return array_values($subscription->getItems()->toArray());
    }

    private function collector(): EventCollector
    {
        $collector = self::getContainer()->get('jpm_martin_sylius_subscription.test.event_collector');
        self::assertInstanceOf(EventCollector::class, $collector);

        return $collector;
    }
}
