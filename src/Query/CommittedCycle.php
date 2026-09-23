<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Query;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;

/** A renewal an item of a subscription is expected to make: when, and how many units of its variant. */
final class CommittedCycle
{
    public function __construct(
        public readonly SubscriptionInterface $subscription,
        public readonly SubscriptionItemInterface $item,
        public readonly int $number,
        public readonly \DateTimeImmutable $date,
        public readonly int $quantity,
    ) {
    }
}
