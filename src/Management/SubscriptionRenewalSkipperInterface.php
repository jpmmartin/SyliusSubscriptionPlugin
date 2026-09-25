<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Management;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;

/** A customer's way to leave out the next renewal without pausing: the one after keeps its date. */
interface SubscriptionRenewalSkipperInterface
{
    /**
     * The open cycle of an active subscription, while it has no order yet and the renewals skipped in a
     * row leave room for another; null when nothing can be skipped.
     */
    public function renewalToSkip(SubscriptionInterface $subscription): ?SubscriptionCycleInterface;

    public function canSkip(SubscriptionInterface $subscription): bool;

    /**
     * Cancels the renewal to skip, marked as skipped, and schedules the next date of the calendar.
     *
     * @return SubscriptionCycleInterface the skipped cycle
     */
    public function skip(SubscriptionInterface $subscription): SubscriptionCycleInterface;
}
