<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Event;

use Doctrine\DBAL\Connection;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalPaid;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionEventInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionSuspended;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Event\EventCollector;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Event\PublishThenFail;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;

/** An event published while a cycle is processed is delivered once the change is stored, and only then. */
final class EventDeliveryTest extends LifecycleTestCase
{
    public function testAHandlerOfTheRenewalPaidReadsTheCyclePaidAndNoTransactionOpen(): void
    {
        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedCoffeeOrder());

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $seen = [];
        $this->collector()->onReceive(static function (SubscriptionEventInterface $event) use ($connection, &$seen): void {
            if ($event instanceof RenewalPaid) {
                $seen[] = [
                    $connection->fetchOne('SELECT state FROM jpm_martin_sylius_subscription_cycle WHERE id = ?', [$event->cycleId]),
                    $connection->isTransactionActive(),
                ];
            }
        });

        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();

        self::assertSame([['paid', false]], $seen, 'Delivered once, after the transaction that stored the cycle was committed.');
    }

    public function testAnEventOfAChangeThatIsNotStoredIsNotDelivered(): void
    {
        /** @var MessageBusInterface $commandBus */
        $commandBus = self::getContainer()->get('sylius.command_bus');

        try {
            $commandBus->dispatch(new PublishThenFail(new SubscriptionSuspended(7)));
            self::fail('The command should have failed.');
        } catch (HandlerFailedException) {
        }

        self::assertSame([], $this->collector()->events());
    }

    public function testTheEventBusAcceptsAnEventNobodyHandles(): void
    {
        // What a store that listens to none of the plugin's events has: an event bus without their handlers.
        /** @var MessageBusInterface $eventBus */
        $eventBus = self::getContainer()->get('sylius.event_bus');
        $unhandled = new \stdClass();

        $envelope = $eventBus->dispatch($unhandled, [new DispatchAfterCurrentBusStamp()]);

        self::assertSame($unhandled, $envelope->getMessage());
    }

    private function collector(): EventCollector
    {
        $collector = self::getContainer()->get('jpm_martin_sylius_subscription.test.event_collector');
        self::assertInstanceOf(EventCollector::class, $collector);

        return $collector;
    }
}
