<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Gate;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;

/**
 * Asked before a due cycle places its order, so nothing is reserved while it waits. A store adds one
 * with the "jpm_martin_sylius_subscription.cycle_gate" tag; with none registered, every cycle passes.
 */
interface CycleGateInterface
{
    public function check(SubscriptionCycleInterface $cycle): GateDecision;
}
