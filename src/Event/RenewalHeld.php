<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

/** A gate holds the cycle until $holdUntil at the latest, for $reason. */
final readonly class RenewalHeld implements SubscriptionEventInterface
{
    public function __construct(
        public int $subscriptionId,
        public int $cycleId,
        public int $cycleNumber,
        public ?\DateTimeImmutable $holdUntil,
        public ?string $reason,
    ) {
    }

    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;
    }
}
