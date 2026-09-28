<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Command\Shop;

use JpmMartin\SyliusSubscriptionPlugin\Api\SubscriptionIdAware;

/** Changes where, and to whom, the customer's subscription renews; the billing address is the shipping one unless given. Through the shop API: the id is the URI's. */
#[SubscriptionIdAware]
final readonly class ChangeSubscriptionAddress
{
    public function __construct(
        public string $subscriptionId,
        /** @var array<mixed> the fields of an address, as sent: the handler checks them */
        public array $shippingAddress = [],
        /** @var array<mixed>|null */
        public ?array $billingAddress = null,
        public ?string $shippingMethod = null,
    ) {
    }
}
