<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Command\Shop;

use JpmMartin\SyliusSubscriptionPlugin\Api\SubscriptionIdAware;

/** Changes the quantity or the variant of items of the customer's subscription, or removes them. Through the shop API: the id is the URI's. */
#[SubscriptionIdAware]
final readonly class ChangeSubscriptionItems
{
    public function __construct(
        public string $subscriptionId,
        /** @var array<mixed> each {id, quantity?, productVariant?, removed?}, as sent: the handler checks them */
        public array $items = [],
        public ?string $acceptedConsentVersion = null,
    ) {
    }
}
