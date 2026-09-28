<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Pricing;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;

/**
 * Whether a price increase must be accepted by the subscription's customer before it applies, or only
 * announced. Replace it to ask for it in some channels or countries only.
 */
interface PriceIncreaseAcceptancePolicyInterface
{
    public function requiresAcceptance(SubscriptionInterface $subscription): bool;
}
