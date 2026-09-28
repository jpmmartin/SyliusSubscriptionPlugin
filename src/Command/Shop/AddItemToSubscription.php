<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Command\Shop;

use JpmMartin\SyliusSubscriptionPlugin\Api\SubscriptionIdAware;

/** Adds a product to the customer's subscription. Through the shop API: the id is the URI's. */
#[SubscriptionIdAware]
final readonly class AddItemToSubscription
{
    public function __construct(
        public string $subscriptionId,
        public string $productVariant = '',
        public int $quantity = 1,
        public ?string $acceptedConsentVersion = null,
    ) {
    }
}
