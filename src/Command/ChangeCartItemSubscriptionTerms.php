<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Command;

use Sylius\Bundle\ApiBundle\Attribute\OrderItemIdAware;
use Sylius\Bundle\ApiBundle\Attribute\OrderTokenValueAware;

/**
 * Changes a single cart line's subscription through the shop API: one of its variant's plans or one of the
 * channel's frequencies, by code, or neither to buy it once (choose-subscription-per-cart-line). The same three
 * options the cart page's own dropdown offers; validated by CartItemSubscriptionTermsOffered.
 */
#[OrderTokenValueAware]
#[OrderItemIdAware]
final class ChangeCartItemSubscriptionTerms
{
    public function __construct(
        public readonly string $orderTokenValue,
        public readonly mixed $orderItemId,
        public readonly ?string $subscriptionPlan = null,
        public readonly ?string $subscriptionFrequency = null,
    ) {
    }
}
