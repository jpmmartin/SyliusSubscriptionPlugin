<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Model\ResourceInterface;

/**
 * One product of a subscription: a quantity of a variant at the price frozen when the customer
 * subscribed, renewed on the terms of its plan or of the store's frequency it was repeated with.
 * Every item of a subscription shares its interval, so one order carries them all.
 */
interface SubscriptionItemInterface extends ResourceInterface
{
    public function getSubscription(): ?SubscriptionInterface;

    public function setSubscription(?SubscriptionInterface $subscription): void;

    public function getProductVariant(): ?ProductVariantInterface;

    public function setProductVariant(?ProductVariantInterface $productVariant): void;

    public function getQuantity(): int;

    public function setQuantity(int $quantity): void;

    /** Frozen at subscription time, and on a change of frequency; in the subscription's currency. */
    public function getUnitPrice(): int;

    public function setUnitPrice(int $unitPrice): void;

    /** The plan of its variant it renews on, if it came from a line with a plan. */
    public function getPlan(): ?SubscriptionPlanInterface;

    public function setPlan(?SubscriptionPlanInterface $plan): void;

    /** The store's frequency it renews on, if it came from a repeated cart. */
    public function getFrequency(): ?SubscriptionFrequencyInterface;

    public function setFrequency(?SubscriptionFrequencyInterface $frequency): void;

    /** Its plan, or else its frequency: its discount and maximum of cycles. */
    public function getTerms(): ?SubscriptionTermsInterface;

    /** The line of the initial order this item was created from. */
    public function getOriginOrderItem(): ?OrderItemInterface;

    public function setOriginOrderItem(?OrderItemInterface $originOrderItem): void;

    /** The cycles charged with this item in them, the initial order included. */
    public function getPaidCycles(): int;

    public function setPaidCycles(int $paidCycles): void;

    /** Whether it still goes into renewals: its terms have no maximum, or it has not been reached. */
    public function isRenewable(): bool;
}
