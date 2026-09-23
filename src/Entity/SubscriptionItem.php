<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

class SubscriptionItem implements SubscriptionItemInterface
{
    protected ?int $id = null;

    protected ?SubscriptionInterface $subscription = null;

    protected ?ProductVariantInterface $productVariant = null;

    protected int $quantity = 1;

    protected int $unitPrice = 0;

    protected ?SubscriptionPlanInterface $plan = null;

    protected ?SubscriptionFrequencyInterface $frequency = null;

    protected ?OrderItemInterface $originOrderItem = null;

    protected int $paidCycles = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubscription(): ?SubscriptionInterface
    {
        return $this->subscription;
    }

    public function setSubscription(?SubscriptionInterface $subscription): void
    {
        $this->subscription = $subscription;
    }

    public function getProductVariant(): ?ProductVariantInterface
    {
        return $this->productVariant;
    }

    public function setProductVariant(?ProductVariantInterface $productVariant): void
    {
        $this->productVariant = $productVariant;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): void
    {
        $this->quantity = $quantity;
    }

    public function getUnitPrice(): int
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(int $unitPrice): void
    {
        $this->unitPrice = $unitPrice;
    }

    public function getPlan(): ?SubscriptionPlanInterface
    {
        return $this->plan;
    }

    public function setPlan(?SubscriptionPlanInterface $plan): void
    {
        $this->plan = $plan;
    }

    public function getOriginOrderItem(): ?OrderItemInterface
    {
        return $this->originOrderItem;
    }

    public function setOriginOrderItem(?OrderItemInterface $originOrderItem): void
    {
        $this->originOrderItem = $originOrderItem;
    }

    public function getPaidCycles(): int
    {
        return $this->paidCycles;
    }

    public function setPaidCycles(int $paidCycles): void
    {
        $this->paidCycles = $paidCycles;
    }

    public function getFrequency(): ?SubscriptionFrequencyInterface
    {
        return $this->frequency;
    }

    public function setFrequency(?SubscriptionFrequencyInterface $frequency): void
    {
        $this->frequency = $frequency;
    }

    public function getTerms(): ?SubscriptionTermsInterface
    {
        return $this->plan ?? $this->frequency;
    }

    public function isRenewable(): bool
    {
        $maxCycles = $this->getTerms()?->getMaxCycles();

        return null === $maxCycles || $this->paidCycles < $maxCycles;
    }
}
