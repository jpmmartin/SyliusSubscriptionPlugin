<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Event;

use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionEventInterface;

/** A command whose handler publishes an event and then fails, as a cycle whose change is not stored. */
final readonly class PublishThenFail
{
    public function __construct(public SubscriptionEventInterface $event)
    {
    }
}
