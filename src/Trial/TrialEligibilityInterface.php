<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Trial;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * Whether a customer may still get the free trial of a variant. Replace it, or point this interface's
 * alias at your own service, to tell the customers who had one apart another way, by their card or
 * their address for instance.
 */
interface TrialEligibilityInterface
{
    public function isEligible(CustomerInterface $customer, ProductVariantInterface $variant): bool;
}
