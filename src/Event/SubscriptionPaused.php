<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

/** The subscription was paused, by its customer or an administrator on the customer's behalf. */
final readonly class SubscriptionPaused implements SubscriptionEventInterface
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
