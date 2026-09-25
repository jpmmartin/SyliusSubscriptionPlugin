<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Management;

use Sylius\Component\Core\Model\ProductVariantInterface;

/** A variant the customer asks to add to a subscription, and how many of it. */
final readonly class SubscriptionItemAddition
{
    public function __construct(
        public ProductVariantInterface $variant,
        public int $quantity,
    ) {
    }
}
