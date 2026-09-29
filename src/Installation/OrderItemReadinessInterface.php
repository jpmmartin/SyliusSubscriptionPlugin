<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Installation;

/**
 * Whether the store has made the one installation step no recipe can make: its order item holds the
 * plan, or the frequency, a line renews on. Until it has, the plugin offers no subscription, so no
 * line chosen as one can silently become a one-off purchase.
 */
interface OrderItemReadinessInterface
{
    public function isReady(): bool;

    /** The order item class the store configures, which has to carry the plan. */
    public function orderItemClass(): string;
}
