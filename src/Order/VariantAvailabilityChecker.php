<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Order;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleItemInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;
use Webmozart\Assert\Assert;

/**
 * Whether a variant can be sold in a channel for a quantity: enabled, with its product, in the channel,
 * and in stock when its stock is tracked. The renewal and the item changes ask here alike.
 */
final class VariantAvailabilityChecker
{
    public function __construct(private readonly AvailabilityCheckerInterface $availabilityChecker)
    {
    }

    /** Why the variant cannot be sold, one of SubscriptionCycleItemInterface's SKIPPED_ reasons; null when it can. */
    public function whyUnavailable(ProductVariantInterface $variant, ChannelInterface $channel, int $quantity): ?string
    {
        $product = $variant->getProduct();
        Assert::isInstanceOf($product, ProductInterface::class);

        if (!$variant->isEnabled() || !$product->isEnabled()) {
            return SubscriptionCycleItemInterface::SKIPPED_DISABLED;
        }

        if (!$product->hasChannel($channel)) {
            return SubscriptionCycleItemInterface::SKIPPED_NOT_IN_CHANNEL;
        }

        if (!$this->availabilityChecker->isStockSufficient($variant, $quantity)) {
            return SubscriptionCycleItemInterface::SKIPPED_OUT_OF_STOCK;
        }

        return null;
    }

    public function isAvailable(ProductVariantInterface $variant, ChannelInterface $channel, int $quantity): bool
    {
        return null === $this->whyUnavailable($variant, $channel, $quantity);
    }
}
