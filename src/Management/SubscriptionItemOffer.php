<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Management;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionTermsInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * A variant a subscription item can move to, or that can be added, with the plan or store frequency it
 * would renew on and the unit price it would be frozen at: today's price less that discount.
 */
final readonly class SubscriptionItemOffer
{
    public function __construct(
        public ProductVariantInterface $variant,
        public SubscriptionTermsInterface $terms,
        public int $unitPrice,
    ) {
    }
}
