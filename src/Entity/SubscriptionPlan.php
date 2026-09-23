<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Model\ToggleableTrait;

class SubscriptionPlan implements SubscriptionPlanInterface
{
    use ToggleableTrait;

    protected ?int $id = null;

    protected ?string $code = null;

    protected ?string $name = null;

    protected ?ProductVariantInterface $productVariant = null;

    protected int $intervalCount = 1;

    protected SubscriptionIntervalUnit $intervalUnit = SubscriptionIntervalUnit::Month;

    protected int $discountPercentage = 0;

    protected ?int $maxCycles = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): void
    {
        $this->code = $code;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getProductVariant(): ?ProductVariantInterface
    {
        return $this->productVariant;
    }

    public function setProductVariant(?ProductVariantInterface $productVariant): void
    {
        $this->productVariant = $productVariant;
    }

    public function getIntervalCount(): int
    {
        return $this->intervalCount;
    }

    public function setIntervalCount(int $intervalCount): void
    {
        $this->intervalCount = $intervalCount;
    }

    public function getIntervalUnit(): SubscriptionIntervalUnit
    {
        return $this->intervalUnit;
    }

    public function setIntervalUnit(SubscriptionIntervalUnit $intervalUnit): void
    {
        $this->intervalUnit = $intervalUnit;
    }

    public function getDiscountPercentage(): int
    {
        return $this->discountPercentage;
    }

    public function setDiscountPercentage(int $discountPercentage): void
    {
        $this->discountPercentage = $discountPercentage;
    }

    public function getMaxCycles(): ?int
    {
        return $this->maxCycles;
    }

    public function setMaxCycles(?int $maxCycles): void
    {
        $this->maxCycles = $maxCycles;
    }
}
