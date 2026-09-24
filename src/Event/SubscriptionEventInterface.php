<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

/**
 * What every event of a subscription's life carries. The plugin publishes them on sylius.event_bus;
 * a store listens to one of them, or to all of them through this interface, to tell its customers.
 * The plugin itself sends no email.
 */
interface SubscriptionEventInterface
{
    public function getSubscriptionId(): int;
}
