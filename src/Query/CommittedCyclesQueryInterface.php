<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Query;

use Sylius\Component\Core\Model\ProductVariantInterface;

/** Read-only: what the active subscriptions of a variant will renew, for planning stock or production. */
interface CommittedCyclesQueryInterface
{
    /**
     * For each item of the variant still renewing, from its subscription's open cycle, which is
     * committed even when overdue or held, to the horizon counted from now, and never past the cycles
     * the item's plan still allows. An item may still be skipped when its cycle comes, if the variant
     * cannot be sold then.
     *
     * @return list<CommittedCycle> by date
     */
    public function forProductVariant(ProductVariantInterface $productVariant, \DateInterval $horizon): array;
}
