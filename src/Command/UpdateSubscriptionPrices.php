<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Command;

/**
 * Reprices the items of one subscription that are on a plan, on a store frequency or of a variant, as
 * PriceUpdateTarget names them: one message per subscription, each in its own transaction. Route it to
 * an asynchronous transport when an update may reach thousands of subscriptions.
 */
final class UpdateSubscriptionPrices
{
    public function __construct(
        public readonly int $subscriptionId,
        public readonly string $targetType,
        public readonly int $targetId,
    ) {
    }
}
