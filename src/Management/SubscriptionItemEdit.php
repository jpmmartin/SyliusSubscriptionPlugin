<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Management;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Webmozart\Assert\Assert;

/** What the customer asks for one item: it starts as the item is, and a form or a caller changes it. */
final class SubscriptionItemEdit
{
    public int $quantity;

    public ProductVariantInterface $variant;

    public bool $removed = false;

    public function __construct(public readonly SubscriptionItemInterface $item)
    {
        $variant = $item->getProductVariant();
        Assert::notNull($variant);

        $this->quantity = $item->getQuantity();
        $this->variant = $variant;
    }

    public function changesVariant(): bool
    {
        return $this->variant !== $this->item->getProductVariant();
    }

    public function changesAnything(): bool
    {
        return $this->removed || $this->changesVariant() || $this->quantity !== $this->item->getQuantity();
    }
}
