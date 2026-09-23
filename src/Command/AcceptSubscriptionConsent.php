<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Command;

use Sylius\Bundle\ApiBundle\Attribute\OrderTokenValueAware;

/**
 * Accepts, on a cart, the current recurring-charge consent text: the API's counterpart of the
 * checkbox on the last checkout step. Completing a cart with subscriptions requires it.
 */
#[OrderTokenValueAware]
final class AcceptSubscriptionConsent
{
    public function __construct(public readonly string $orderTokenValue)
    {
    }
}
