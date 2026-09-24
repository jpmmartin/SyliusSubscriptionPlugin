<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

/** The subscription was suspended, by an administrator or after cycles failed in a row. */
final readonly class SubscriptionSuspended implements SubscriptionEventInterface
{
    public function __construct(
        public int $subscriptionId,
    ) {
    }

    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;
    }
}
