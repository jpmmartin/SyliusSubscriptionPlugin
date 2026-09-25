<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Command;

/**
 * Skip the next renewal of the subscription. Handled on sylius.command_bus, so the skip is stored in one
 * transaction and its event delivered only once it is.
 */
final class SkipSubscriptionRenewal
{
    public function __construct(
        public readonly int $subscriptionId,
    ) {
    }
}
