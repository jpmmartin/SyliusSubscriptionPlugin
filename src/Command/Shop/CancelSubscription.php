<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Command\Shop;

use JpmMartin\SyliusSubscriptionPlugin\Api\SubscriptionIdAware;

/** Cancels the customer's subscription, or has it cancelled once the deliveries it paid for are placed. Through the shop API: the id is the URI's. */
#[SubscriptionIdAware]
final readonly class CancelSubscription
{
    public function __construct(
        public string $subscriptionId,
    ) {
    }
}
