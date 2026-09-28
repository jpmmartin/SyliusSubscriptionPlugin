<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Pricing;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;

/**
 * An administrator's update of the frozen prices of existing subscriptions: each item on the target
 * is repriced as the cart prices a subscription line, from its variant's current price in the
 * subscription's channel less the discount of its plan or store frequency. Cancelled and completed
 * subscriptions, and removed items, are left as they are; so is a renewal order already placed.
 */
interface SubscriptionPriceUpdaterInterface
{
    /** What updating the target would do, before anything changes. */
    public function preview(PriceUpdateTarget $target): PriceUpdatePreview;

    /**
     * Reprices the subscription's items on the target. A lower price applies at once. A higher one is
     * announced, from `price_increase_notice_days` days on, replacing any pending on that item and
     * clearing the customer's acceptance; the same price as the one pending stays as it was. The
     * current price, when an increase was pending, withdraws it.
     */
    public function update(SubscriptionInterface $subscription, PriceUpdateTarget $target): void;
}
