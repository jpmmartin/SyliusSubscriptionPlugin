<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Frequency;

use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * Which variants the store lets customers repeat with one of its frequencies. The mark is kept in a
 * table of the plugin, so the store's variant class needs no change.
 */
interface RepeatableVariantsInterface
{
    public function isRepeatable(ProductVariantInterface $productVariant): bool;

    /**
     * The given variants that can be repeated, found with a single query.
     *
     * @param iterable<ProductVariantInterface> $productVariants
     *
     * @return list<ProductVariantInterface>
     */
    public function filterRepeatable(iterable $productVariants): array;

    /** Marks or unmarks the variant; the caller flushes, as a variant's save already does. */
    public function markRepeatable(ProductVariantInterface $productVariant, bool $repeatable): void;
}
