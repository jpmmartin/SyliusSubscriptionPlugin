<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

use Sylius\Component\Core\Model\ProductVariantInterface;

class RepeatableVariant implements RepeatableVariantInterface
{
    protected ?int $id = null;

    protected ?ProductVariantInterface $productVariant = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProductVariant(): ?ProductVariantInterface
    {
        return $this->productVariant;
    }

    public function setProductVariant(?ProductVariantInterface $productVariant): void
    {
        $this->productVariant = $productVariant;
    }
}
