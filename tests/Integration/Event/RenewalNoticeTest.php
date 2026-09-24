<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Event;

use JpmMartin\SyliusSubscriptionPlugin\Console\Command\ProcessSubscriptionCyclesCommand;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalUpcoming;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionCycleRepositoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionCalendarInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Event\EventCollector;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;

/** A monthly Coffee subscription activated on 1 February, whose second cycle renews on 1 March at 09:00. */
final class RenewalNoticeTest extends LifecycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->itIsNow('2027-02-01 09:00');
        $this->pay($this->placedCoffeeOrder());
        $this->collector()->clear();
    }

    public function testTheRenewalIsAnnouncedThreeDaysBeforeWithItsDate(): void
    {
        $this->itIsNow('2027-02-26 09:00');
        $display = $this->runTheCycleCommand()->getDisplay();

        [, $second] = $this->storedCycles($this->subscription());
        self::assertEquals(
            [new RenewalUpcoming((int) $this->subscription()->getId(), (int) $second->getId(), 2, new \DateTimeImmutable('2027-03-01 09:00'))],
            $this->collector()->events(),
        );
        self::assertSame('2027-02-26 09:00', $second->getRenewalNoticeAt()?->format('Y-m-d H:i'));
        self::assertStringContainsString('1 renewal(s) announced', (string) preg_replace('/\s+/', ' ', $display));
    }

    public function testTheRenewalIsAnnouncedOnce(): void
    {
        foreach (['2027-02-26 09:00', '2027-02-27 09:00', '2027-02-28 09:00'] as $dateTime) {
            $this->itIsNow($dateTime);
            $this->runTheCycleCommand();
        }

        self::assertCount(1, $this->collector()->events(RenewalUpcoming::class));
    }

    public function testNothingIsAnnouncedBeforeTheNoticePeriod(): void
    {
        $this->itIsNow('2027-02-20 09:00');
        $this->runTheCycleCommand();

        self::assertSame([], $this->collector()->events());
    }

    public function testNothingIsAnnouncedWhenTheStoreTurnsTheNoticeOff(): void
    {
        $this->itIsNow('2027-02-26 09:00');
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
        $command = new ProcessSubscriptionCyclesCommand($cycles, $commandBus, $clock, $calendar, $this->entityManager(), 'skip', null);
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([], $this->collector()->events());
        [, $second] = $this->storedCycles($this->subscription());
        self::assertNull($second->getRenewalNoticeAt());
    }

    public function testASuspendedSubscriptionIsNotAnnounced(): void
    {
        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_SUSPEND);
        $this->entityManager()->flush();
        $this->collector()->clear();

        $this->itIsNow('2027-02-26 09:00');
        $this->runTheCycleCommand();

        self::assertSame([], $this->collector()->events(RenewalUpcoming::class));
    }

    public function testACycleThatIsAlreadyDueIsNotAnnounced(): void
    {
        // The command did not run until the renewal was due: it is charged, not announced.
        $this->itIsNow('2027-03-02 09:00');
        $this->runTheCycleCommand();

        self::assertSame([], $this->collector()->events(RenewalUpcoming::class));
    }

    private function subscription(): SubscriptionInterface
    {
        $subscriptions = $this->storedSubscriptions();
        self::assertCount(1, $subscriptions);

        return $subscriptions[0];
    }

    private function collector(): EventCollector
    {
        $collector = self::getContainer()->get('jpm_martin_sylius_subscription.test.event_collector');
        self::assertInstanceOf(EventCollector::class, $collector);

        return $collector;
    }
}
