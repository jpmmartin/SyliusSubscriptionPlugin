<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

/** The subscription was activated: its initial order was paid. */
final readonly class SubscriptionActivated implements SubscriptionEventInterface
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
