<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Management;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionInterval;

/** The same change whether the customer asks for it in their account or an administrator makes it. */
interface SubscriptionFrequencyChangerInterface
{
    /**
     * The intervals, other than the subscription's own, that every item can move to: for an item on a
     * plan, an enabled plan of its variant; for an item repeated with a store frequency, an enabled
     * frequency of the subscription's channel. None unless it is active and its open cycle has not
     * been charged yet: an order awaiting payment keeps the old prices.
     *
     * @return list<SubscriptionInterval> the shortest first
     */
    public function frequenciesToChangeTo(SubscriptionInterface $subscription): array;

    /**
     * From the open cycle, which keeps its date, and without proration: each item moves to its
     * variant's plan, or to the store's frequency, with that interval, the oldest one if there are
     * several, and its price is frozen again from the variant's current price and the new discount.
     * An item that no longer renewed renews again if its new terms allow more cycles than it has been
     * paid. The calendar follows the new interval from that date.
     *
     * @throws \InvalidArgumentException when the interval is not one of frequenciesToChangeTo()
     */
    public function change(SubscriptionInterface $subscription, SubscriptionInterval $interval): void;
}
