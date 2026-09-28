<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Command\Shop;

use JpmMartin\SyliusSubscriptionPlugin\Api\SubscriptionIdAware;

/** Moves the customer's subscription to another of the intervals offered to it. Through the shop API: the id is the URI's. */
#[SubscriptionIdAware]
final readonly class ChangeSubscriptionFrequency
{
    public function __construct(
        public string $subscriptionId,
        public int $intervalCount = 0,
        public string $intervalUnit = '',
    ) {
    }
}
