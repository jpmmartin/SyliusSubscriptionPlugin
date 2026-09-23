<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Management;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;

/** An administrator's second chance for a failed cycle, outside the schedule. */
interface SubscriptionCycleRetrierInterface
{
    /** A failed cycle of an active or suspended subscription. */
    public function canRetry(SubscriptionCycleInterface $cycle): bool;

    /**
     * Places a new order for the cycle, with the items that can be sold now, and charges it once. The
     * cycle is then paid, failed again with the reason, or awaiting the outcome of a charge that the
     * next runs reconcile. The run of failures is left as it was, and so are the calendar and the
     * subscription's state, unless the retry pays the last cycle its items had left.
     *
     * @throws \InvalidArgumentException when the cycle cannot be retried
     */
    public function retry(SubscriptionCycleInterface $cycle): void;
}
