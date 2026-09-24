<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

/**
 * A charge of the renewal order was declined, or not attempted, and will be tried again at $nextAttemptAt.
 */
final readonly class RenewalChargeDeclined implements SubscriptionEventInterface
{
    public function __construct(
        public int $subscriptionId,
        public int $cycleId,
        public int $cycleNumber,
        public int $orderId,
        public \DateTimeImmutable $nextAttemptAt,
        public ?string $reason,
        public ?string $code,
    ) {
    }

    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;
    }
}
