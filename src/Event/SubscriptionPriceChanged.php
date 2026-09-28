<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

/**
 * The subscription's prices changed: a decrease an administrator's price update applied at once, or an
 * announced increase applied when the first renewal it covers was processed. The totals are what each
 * renewal costs before and after, in minor units of the subscription's currency.
 */
final readonly class SubscriptionPriceChanged implements SubscriptionEventInterface
{
    public function __construct(
        public int $subscriptionId,
        public int $previousRenewalTotal,
        public int $renewalTotal,
    ) {
    }

    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;
    }
}
