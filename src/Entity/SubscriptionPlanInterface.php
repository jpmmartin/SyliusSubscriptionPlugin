<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Model\CodeAwareInterface;
use Sylius\Resource\Model\ResourceInterface;
use Sylius\Resource\Model\ToggleableInterface;

/**
 * A way of subscribing to one product variant: how often it renews, how much cheaper it is than
 * buying the variant once, and optionally after how many cycles it ends.
 *
 * The variant stays the physical article, so buying it once and subscribing to it draw on the same
 * stock. Disabling a plan stops it being offered; subscriptions already on it keep renewing.
 */
interface SubscriptionPlanInterface extends ResourceInterface, CodeAwareInterface, ToggleableInterface, SubscriptionTermsInterface
{
    public function setName(?string $name): void;

    public function getProductVariant(): ?ProductVariantInterface;

    public function setProductVariant(?ProductVariantInterface $productVariant): void;

    public function setIntervalCount(int $intervalCount): void;

    public function setIntervalUnit(SubscriptionIntervalUnit $intervalUnit): void;

    public function setDiscountPercentage(int $discountPercentage): void;

    public function setMaxCycles(?int $maxCycles): void;
}
