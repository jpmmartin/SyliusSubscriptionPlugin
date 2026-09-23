<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Command;

use Sylius\Bundle\ApiBundle\Attribute\OrderTokenValueAware;

/**
 * "Repeat this cart" through the shop API: repeats the cart with one of the frequencies its channel
 * offers, given by its code, or stops repeating it when the code is null.
 */
#[OrderTokenValueAware]
final class RepeatCart
{
    public function __construct(
        public readonly string $orderTokenValue,
        public readonly ?string $subscriptionFrequencyCode = null,
    ) {
    }
}
