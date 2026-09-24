<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

/**
 * The cycle was cancelled: its order was cancelled before being charged, the subscription stopped, or the
 * cycle was skipped as late.
 */
final readonly class RenewalCancelled implements SubscriptionEventInterface
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
