<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Command\Shop;

use JpmMartin\SyliusSubscriptionPlugin\Api\SubscriptionIdAware;

/** Accepts the price increase announced on the customer's subscription, and resumes it when asked. Through the shop API: the id is the URI's. */
#[SubscriptionIdAware]
final readonly class AcceptSubscriptionPriceIncrease
{
    public function __construct(
        public string $subscriptionId,
        public bool $resume = false,
    ) {
    }
}
