<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

/**
 * The renewal of $scheduledAt was skipped, by its customer or an administrator on the customer's behalf;
 * the subscription renews next on $nextScheduledAt.
 */
final readonly class RenewalSkipped implements SubscriptionEventInterface
{
    public function __construct(
        public int $subscriptionId,
        public int $cycleId,
        public int $cycleNumber,
        public \DateTimeImmutable $scheduledAt,
        public \DateTimeImmutable $nextScheduledAt,
    ) {
    }

    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;
    }
}
