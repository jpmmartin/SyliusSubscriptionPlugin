<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Payment;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;

/** Where the customer pays a renewal whose charge was declined, or recovers a suspended subscription, for the store's own notices. */
interface RenewalPaymentLinkGeneratorInterface
{
    /**
     * The absolute address of the store's order payment page for the cycle's renewal order, in the
     * order's language, on the channel's hostname when it has one. Null unless the cycle awaits a retry
     * after a declined or unattempted charge, with a payment left to pay: a charge whose outcome is
     * still unknown may have gone through.
     */
    public function generate(SubscriptionCycleInterface $cycle): ?string;

    /**
     * The absolute address where the customer of a subscription suspended after failed cycles recovers
     * it: once signed in, it starts the recovery and leads to the order payment page, so it works
     * before the recovery's order exists. In the language of the subscription's last order. Null
     * unless the customer can recover it now.
     */
    public function generateRecovery(SubscriptionInterface $subscription): ?string;
}
