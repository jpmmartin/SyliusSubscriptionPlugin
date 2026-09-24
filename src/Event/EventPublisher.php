<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Publishes the plugin's events on sylius.event_bus, as Sylius publishes its own. An event published while
 * a message of sylius.command_bus is handled, such as a cycle of the cycles command, is delivered once that
 * message's transaction is committed, and not at all if it fails; otherwise it is delivered at once.
 */
final class EventPublisher
{
    public function __construct(private readonly MessageBusInterface $eventBus)
    {
    }

    public function publish(SubscriptionEventInterface $event): void
    {
        $this->eventBus->dispatch($event, [new DispatchAfterCurrentBusStamp()]);
    }
}
