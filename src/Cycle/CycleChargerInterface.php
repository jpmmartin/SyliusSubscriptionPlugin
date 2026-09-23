<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Cycle;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Payment\ChargeOutcome;

/**
 * Each call is recorded as an attempt of the cycle, and what came of it is followed through: an
 * approval pays the cycle by way of its order's payment; a decline, or a charge that could not be
 * attempted, is retried when the retry policy says so and otherwise fails the cycle, except in an
 * administrator's retry, which fails at once; an unknown outcome is asked about on the next run
 * instead of being charged again.
 */
interface CycleChargerInterface
{
    /** Charges the cycle's order once. */
    public function charge(SubscriptionCycleInterface $cycle): ChargeOutcome;

    /** Asks what became of the cycle's last charge, whose outcome was unknown. Never charges. */
    public function reconcile(SubscriptionCycleInterface $cycle): ChargeOutcome;
}
