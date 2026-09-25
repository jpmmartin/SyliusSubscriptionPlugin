<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Payment;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;

/** Where the customer pays a renewal whose charge was declined, for the store's own notices. */
interface RenewalPaymentLinkGeneratorInterface
{
    /**
     * The absolute address of the store's order payment page for the cycle's renewal order, in the
     * order's language, on the channel's hostname when it has one. Null unless the cycle awaits a retry
     * after a declined or unattempted charge, with a payment left to pay: a charge whose outcome is
     * still unknown may have gone through.
     */
    public function generate(SubscriptionCycleInterface $cycle): ?string;
}
