<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Command;

use Sylius\Bundle\ApiBundle\Attribute\OrderTokenValueAware;
use Sylius\Bundle\ApiBundle\Command\IriToIdentifierConversionAwareInterface;

/**
 * Adds a variant to a cart on one of its subscription plans, through the shop API.
 *
 * Sylius's own AddItemToCart only knows a variant and a quantity, so a subscription line needs a
 * command of its own; one-off lines keep going through Sylius's. The variant may be given as an IRI
 * or as a code, like Sylius's; the plan, which has no API resource, is given by its code.
 */
#[OrderTokenValueAware]
final class AddSubscriptionItemToCart implements IriToIdentifierConversionAwareInterface
{
    public function __construct(
        public readonly string $orderTokenValue,
        public readonly string $productVariantCode,
        public readonly string $subscriptionPlanCode,
        public readonly int $quantity = 1,
    ) {
    }
}
