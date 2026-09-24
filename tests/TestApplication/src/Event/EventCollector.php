<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Event;

use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionEventInterface;

/**
 * The test store's only listener of the plugin's events: it keeps each one it receives, and runs what a
 * test asks it to on receiving one, to look at the database as the event is delivered.
 */
final class EventCollector
{
    /** @var list<SubscriptionEventInterface> */
    private array $events = [];

    /** @var list<callable(SubscriptionEventInterface): void> */
    private array $onReceive = [];

    public function __invoke(SubscriptionEventInterface $event): void
    {
        $this->events[] = $event;
        foreach ($this->onReceive as $callback) {
            $callback($event);
        }
    }

    /** @param callable(SubscriptionEventInterface): void $callback */
    public function onReceive(callable $callback): void
    {
        $this->onReceive[] = $callback;
    }

    /**
     * @template T of SubscriptionEventInterface
     *
     * @param class-string<T>|null $class
     *
     * @return ($class is null ? list<SubscriptionEventInterface> : list<T>)
     */
    public function events(?string $class = null): array
    {
        if (null === $class) {
            return $this->events;
        }

        return array_values(array_filter($this->events, static fn (SubscriptionEventInterface $event): bool => $event instanceof $class));
    }

    public function clear(): void
    {
        $this->events = [];
    }
}
