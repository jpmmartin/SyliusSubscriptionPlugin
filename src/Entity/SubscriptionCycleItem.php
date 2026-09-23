<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

class SubscriptionCycleItem implements SubscriptionCycleItemInterface
{
    protected ?int $id = null;

    protected ?SubscriptionCycleInterface $cycle = null;

    protected ?SubscriptionItemInterface $subscriptionItem = null;

    protected int $quantity = 1;

    protected int $unitPrice = 0;

    protected ?string $skippedReason = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCycle(): ?SubscriptionCycleInterface
    {
        return $this->cycle;
    }

    public function setCycle(?SubscriptionCycleInterface $cycle): void
    {
        $this->cycle = $cycle;
    }

    public function getSubscriptionItem(): ?SubscriptionItemInterface
    {
        return $this->subscriptionItem;
    }

    public function setSubscriptionItem(?SubscriptionItemInterface $subscriptionItem): void
    {
        $this->subscriptionItem = $subscriptionItem;
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

    public function getSkippedReason(): ?string
    {
        return $this->skippedReason;
    }

    public function setSkippedReason(?string $skippedReason): void
    {
        $this->skippedReason = $skippedReason;
    }

    public function isIncluded(): bool
    {
        return null === $this->skippedReason;
    }
}
