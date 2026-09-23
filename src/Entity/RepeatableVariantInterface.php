<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Model\ResourceInterface;

/**
 * A variant the store lets customers repeat with one of its frequencies. A variant without one is
 * bought once even in a repeated cart, which keeps gift cards and regulated products out of
 * subscriptions unless the store says otherwise.
 */
interface RepeatableVariantInterface extends ResourceInterface
{
    public function getProductVariant(): ?ProductVariantInterface;

    public function setProductVariant(?ProductVariantInterface $productVariant): void;
}
