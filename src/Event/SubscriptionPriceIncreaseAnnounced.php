<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

/**
 * An administrator's price update announced an increase: each renewal from $appliesFrom on would cost
 * $renewalTotal instead of $previousRenewalTotal, in minor units of the subscription's currency. When
 * $acceptanceRequired, the customer must accept it from their account, or the subscription is paused
 * when it would apply. Tell the customer: the plugin sends nothing.
 */
final readonly class SubscriptionPriceIncreaseAnnounced implements SubscriptionEventInterface
{
    public function __construct(
        public int $subscriptionId,
        public int $previousRenewalTotal,
        public int $renewalTotal,
        public \DateTimeImmutable $appliesFrom,
        public bool $acceptanceRequired,
    ) {
    }

    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;
    }
}
