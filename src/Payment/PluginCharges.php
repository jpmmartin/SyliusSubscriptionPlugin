<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Payment;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionChargeAttemptInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Whether a payment of a cycle's order is one the plugin charged: an attempt of its own carries it, and
 * is on the cycle before the gateway is asked. A charge it did not attempt left the payment for the
 * customer to pay, and so does their own payment.
 */
final class PluginCharges
{
    private const PLUGIN_TYPES = [SubscriptionChargeAttemptInterface::TYPE_CHARGE, SubscriptionChargeAttemptInterface::TYPE_STATUS];

    public static function include(SubscriptionCycleInterface $cycle, PaymentInterface $payment): bool
    {
        foreach ($cycle->getAttempts() as $attempt) {
            if (
                $payment === $attempt->getPayment() &&
                \in_array($attempt->getType(), self::PLUGIN_TYPES, true) &&
                SubscriptionChargeAttemptInterface::OUTCOME_NOT_ATTEMPTED !== $attempt->getOutcome()
            ) {
                return true;
            }
        }

        return false;
    }
}
