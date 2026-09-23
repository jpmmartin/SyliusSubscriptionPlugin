<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

/**
 * What a subscription line or item renews on: a plan of its own variant, or one of the store's
 * frequencies. Everything that prices, groups or ends a subscription reads these terms, not which
 * of the two they come from.
 */
interface SubscriptionTermsInterface
{
    public function getCode(): ?string;

    public function getName(): ?string;

    /** How many units make one interval: 3 for "every 3 months". At least 1. */
    public function getIntervalCount(): int;

    public function getIntervalUnit(): SubscriptionIntervalUnit;

    /** Whole percentage taken off the variant's channel price, from 0 to 100. */
    public function getDiscountPercentage(): int;

    /** Cycles after which an item on these terms stops renewing; null renews until cancelled. */
    public function getMaxCycles(): ?int;
}
