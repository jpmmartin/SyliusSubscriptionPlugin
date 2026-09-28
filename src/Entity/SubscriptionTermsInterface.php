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

    /**
     * Whole percentage taken off the variant's channel price instead of the normal discount during the
     * first getIntroductoryCycles() cycles, from 0 to 100; null offers no introductory price.
     */
    public function getIntroductoryDiscountPercentage(): ?int;

    /** How many cycles the introductory price lasts, the initial order being the first. At least 1. */
    public function getIntroductoryCycles(): int;

    /**
     * Days of free trial a new subscription starts with: its initial order charges nothing for it, and
     * its first charge is the renewal on the day the trial ends. Null offers none. Never together with
     * an introductory price.
     */
    public function getTrialDays(): ?int;
}
