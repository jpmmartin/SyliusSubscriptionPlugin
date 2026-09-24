<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

/** The subscription was cancelled, by its customer or an administrator. */
final readonly class SubscriptionCancelled implements SubscriptionEventInterface
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
