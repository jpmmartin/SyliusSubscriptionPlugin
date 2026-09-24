<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

/**
 * The cycle failed, for $reason: its retries ran out, a gate rejected it, its hold expired or nothing
 * could be renewed. Its order, if it had one, is cancelled.
 */
final readonly class RenewalFailed implements SubscriptionEventInterface
{
    public function __construct(
        public int $subscriptionId,
        public int $cycleId,
        public int $cycleNumber,
        public ?int $orderId,
        public ?string $reason,
    ) {
    }

    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;
    }
}
