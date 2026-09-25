<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

/**
 * The subscription renews with another payment method from now on, the one its customer paid a pending
 * renewal with.
 */
final readonly class SubscriptionPaymentMethodChanged implements SubscriptionEventInterface
{
    public function __construct(
        public int $subscriptionId,
        public string $paymentMethodCode,
    ) {
    }

    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;
    }
}
