<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

/**
 * The subscription's addresses were changed, by its customer or an administrator, and with them its
 * shipping method when $shippingMethodChanged. A changed address is what a stolen account does first:
 * tell the customer.
 */
final readonly class SubscriptionAddressChanged implements SubscriptionEventInterface
{
    public function __construct(
        public int $subscriptionId,
        public bool $shippingMethodChanged,
    ) {
    }

    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;
    }
}
