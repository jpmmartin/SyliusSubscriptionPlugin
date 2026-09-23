<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

/**
 * An order item that can carry the subscription plan the customer chose when adding it to the cart,
 * or the store's frequency its cart is repeated with.
 *
 * The store's OrderItem implements this and uses SubscriptionPlanAwareTrait: the terms have to
 * travel with the line through the cart forms, the merging of lines and the renewal orders, so they
 * live on the line itself. The frequency is set by the plugin whenever the cart is processed, never
 * by hand. An item with neither is a one-off purchase.
 */
interface SubscriptionPlanAwareInterface
{
    public function getSubscriptionPlan(): ?SubscriptionPlanInterface;

    public function setSubscriptionPlan(?SubscriptionPlanInterface $subscriptionPlan): void;

    public function getSubscriptionFrequency(): ?SubscriptionFrequencyInterface;

    public function setSubscriptionFrequency(?SubscriptionFrequencyInterface $subscriptionFrequency): void;

    /** Its plan, or else its frequency; none for a one-off purchase. */
    public function getSubscriptionTerms(): ?SubscriptionTermsInterface;
}
