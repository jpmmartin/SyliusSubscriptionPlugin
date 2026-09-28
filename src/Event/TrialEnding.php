<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

/**
 * The renewal of $scheduledAt is the first charge of a subscription that started with a free trial.
 * Published with RenewalUpcoming, when that renewal is announced; never while the store announces no
 * renewals. $renewalTotal is what the renewal charges for its items, before taxes, shipping and
 * promotions, in minor units of the subscription's currency.
 */
final readonly class TrialEnding implements SubscriptionEventInterface
{
    public function __construct(
        public int $subscriptionId,
        public int $cycleId,
        public int $cycleNumber,
        public \DateTimeImmutable $scheduledAt,
        public int $renewalTotal,
    ) {
    }

    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;
    }
}
