<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Command\Shop;

use JpmMartin\SyliusSubscriptionPlugin\Api\SubscriptionIdAware;

/** Pauses the customer's subscription. Through the shop API: the id is the URI's. */
#[SubscriptionIdAware]
final readonly class PauseSubscription
{
    public function __construct(
        public string $subscriptionId,
    ) {
    }
}
