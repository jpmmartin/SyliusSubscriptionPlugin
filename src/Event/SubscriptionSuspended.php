<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

/**
 * The subscription was suspended: after cycles failed in a row when $forFailedCycles, and its customer
 * can then recover it by paying, or else by an administrator.
 */
final readonly class SubscriptionSuspended implements SubscriptionEventInterface
{
    public function __construct(
        public int $subscriptionId,
        public bool $forFailedCycles = false,
    ) {
    }

    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;
    }
}
