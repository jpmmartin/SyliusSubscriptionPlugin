<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

/**
 * The subscription was suspended, by an administrator or after cycles failed in a row. $forUnpaidRenewals
 * when the last of them failed on a charge: its customer can then recover it by paying.
 */
final readonly class SubscriptionSuspended implements SubscriptionEventInterface
{
    public function __construct(
        public int $subscriptionId,
        public bool $forUnpaidRenewals = false,
    ) {
    }

    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;
    }
}
