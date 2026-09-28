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

    /** The normal price, frozen at subscription time and on a change of terms; in the subscription's currency. */
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

    /**
     * The unit price an announced increase will freeze it at, from the first renewal scheduled on or
     * after getPendingPriceFrom(); null when none is pending. A decrease is applied at once.
     */
    public function getPendingUnitPrice(): ?int;

    public function getPendingPriceFrom(): ?\DateTimeImmutable;

    /** Announces an increase, replacing any pending one; null and null clear it. */
    public function setPendingPrice(?int $pendingUnitPrice, ?\DateTimeImmutable $pendingPriceFrom): void;

    public function hasPendingPrice(): bool;

    /**
     * The unit price of its first getIntroductoryCycles() cycles, the initial order included, agreed on
     * when the customer subscribed; null when it had none, or its terms changed since.
     */
    public function getIntroductoryUnitPrice(): ?int;

    public function getIntroductoryCycles(): ?int;

    /** Agrees on an introductory price; null clears it, which ends it. */
    public function setIntroductoryPrice(?int $introductoryUnitPrice, ?int $introductoryCycles): void;

    /** Whether the next cycle it is charged in is still one of its introductory cycles. */
    public function isOnIntroductoryPrice(): bool;

    /**
     * The unit price of the next cycle it is charged in: its introductory price until it has paid its
     * introductory cycles, and its frozen price after.
     */
    public function getUnitPriceForCycle(): int;

    /** When its customer removed it from the subscription; it stays so the cycles that carried it keep it. */
    public function getRemovedAt(): ?\DateTimeImmutable;

    public function setRemovedAt(?\DateTimeImmutable $removedAt): void;

    public function isRemoved(): bool;

    /**
     * Whether it still goes into renewals: it was not removed, and its terms have no maximum or it has
     * not been reached.
     */
    public function isRenewable(): bool;
}
