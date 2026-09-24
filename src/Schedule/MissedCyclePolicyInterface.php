<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Schedule;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;

/**
 * What becomes of the dates of a subscription's calendar that passed before a cycle could be scheduled
 * on them: after the cycles command stopped for a while, or when a cycle of a short interval spent
 * longer than its interval being retried. Replace the service, or point this interface's alias
 * elsewhere, to decide it another way.
 */
interface MissedCyclePolicyInterface
{
    /**
     * How many of the dates that have come by $now, from cycle $nextNumber's on, to skip before
     * scheduling it: 0 schedules it on its own date even when that date has passed. The scheduler
     * refuses to skip more dates than have come.
     */
    public function datesToSkip(SubscriptionInterface $subscription, int $nextNumber, \DateTimeImmutable $now): int;

    /**
     * Whether a scheduled cycle found due at $now is still processed. One that is not is cancelled
     * without an order, the next is scheduled, and it does not count as a failed cycle.
     */
    public function isStillDue(SubscriptionCycleInterface $cycle, \DateTimeImmutable $now): bool;
}
