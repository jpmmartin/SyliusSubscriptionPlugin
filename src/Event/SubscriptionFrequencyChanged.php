<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

/** The subscription now renews every $intervalCount $intervalUnit ("day", "week", "month" or "year"). */
final readonly class SubscriptionFrequencyChanged implements SubscriptionEventInterface
{
    public function __construct(
        public int $subscriptionId,
        public int $intervalCount,
        public string $intervalUnit,
    ) {
    }

    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;
    }
}
