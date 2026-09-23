<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Cycle;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;

interface CycleFailureHandlerInterface
{
    /**
     * A cycle that cannot be settled fails with its reason, and its order is cancelled if nothing was
     * charged on it. Its subscription goes on to the next cycle, unless this is the configured number
     * of cycles failed in a row, which suspends it. A failed manual retry changes neither.
     */
    public function fail(SubscriptionCycleInterface $cycle, string $reason): void;
}
