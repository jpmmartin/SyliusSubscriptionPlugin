<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Cycle;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Payment\ChargeOutcome;

/**
 * What becomes of a renewal charge that was declined or not attempted: the same order is charged again
 * at a date, or the cycle fails. Replace this service, or point the interface alias elsewhere, to retry
 * another way, for instance by payment method or by the gateway's code.
 */
interface RetryPolicyInterface
{
    /**
     * Asked once the failed attempt is among the cycle's attempts. Returns when to charge the cycle's
     * order again, or null to fail the cycle. Never asked about an administrator's retry, which is
     * charged once.
     */
    public function nextAttemptAt(SubscriptionCycleInterface $cycle, ChargeOutcome $outcome): ?\DateTimeImmutable;
}
