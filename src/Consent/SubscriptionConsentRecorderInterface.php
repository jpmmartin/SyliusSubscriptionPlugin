<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Consent;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionConsentInterface;
use Sylius\Component\Core\Model\OrderInterface;

/** Records a customer's acceptance of the recurring-charge consent text on an order, and tells whether it was given. */
interface SubscriptionConsentRecorderInterface
{
    /**
     * Records acceptance of the current version, in the order's language, now. Accepting again
     * replaces the earlier acceptance. The consent is persisted but not flushed.
     */
    public function record(OrderInterface $order): SubscriptionConsentInterface;

    /** Whether the current version of the text was accepted on this order. */
    public function isGivenFor(OrderInterface $order): bool;

    public function findFor(OrderInterface $order): ?SubscriptionConsentInterface;
}
