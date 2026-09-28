<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

/**
 * The renewal of $scheduledAt is the first in which an item of the subscription is charged its normal
 * price after its introductory one. Published with RenewalUpcoming, when that renewal is announced;
 * never while the store announces no renewals. $renewalTotal is what the renewal charges for its
 * items, before taxes, shipping and promotions, in minor units of the subscription's currency.
 */
final readonly class IntroductoryPriceEnding implements SubscriptionEventInterface
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
