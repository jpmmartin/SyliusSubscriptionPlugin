<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

/**
 * The customer changed, removed or added items of the subscription. The totals are what each renewal
 * costs before and after, in minor units of the subscription's currency, as getRenewalTotal() gives
 * them: when the second is higher, the customer accepted the recurring charges again.
 */
final readonly class SubscriptionItemsChanged implements SubscriptionEventInterface
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
