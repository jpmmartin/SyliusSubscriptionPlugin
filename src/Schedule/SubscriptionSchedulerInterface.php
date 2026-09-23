<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Schedule;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;

interface SubscriptionSchedulerInterface
{
    /**
     * Keeps an active subscription with exactly one open cycle: schedules the next one on the
     * calendar when it has none, and completes the subscription, cancelling any open cycle, once none
     * of its items renews any more. A subscription that is not active is left alone.
     */
    public function scheduleNext(SubscriptionInterface $subscription): void;

    /** The cycle waiting to be charged in the calendar's order, never a failed cycle being retried by hand. */
    public function findOpenCycle(SubscriptionInterface $subscription): ?SubscriptionCycleInterface;
}
