<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Gate;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;

interface CycleGateKeeperInterface
{
    /**
     * Whether the due cycle may place its order now, which only happens when every gate passes.
     * Otherwise the cycle is put or kept on hold, or it fails when a gate rejects it or its hold
     * reaches its deadline.
     */
    public function admit(SubscriptionCycleInterface $cycle): bool;
}
