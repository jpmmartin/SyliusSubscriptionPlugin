<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Payment;

use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

/**
 * How a renewal is charged, without the customer present. The plugin knows no gateway: a store
 * replaces this service when its gateway charges a card on file some other way than through
 * Sylius's payment requests.
 */
interface RenewalChargerInterface
{
    /** Whether payments of this method can be charged without the customer present. Checkout refuses a subscription with any other. */
    public function supports(PaymentMethodInterface $paymentMethod): bool;

    /** Charges the payment's own amount. */
    public function charge(PaymentInterface $payment): ChargeOutcome;

    /** Asks what became of an earlier charge whose outcome was unknown. Never charges again. */
    public function status(PaymentInterface $payment): ChargeOutcome;
}
