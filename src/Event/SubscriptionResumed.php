<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

/** The paused subscription was resumed: its next renewal is on the first date of its calendar after today. */
final readonly class SubscriptionResumed implements SubscriptionEventInterface
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
