<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Event;

/**
 * A cycle placed the order of a delivery its block paid for, without charging it: published instead of
 * RenewalPaid, so no customer is told of a charge there was not. $deliveriesLeft counts the deliveries
 * still paid for after this one.
 */
final readonly class PrepaidDeliveryPlaced implements SubscriptionEventInterface
{
    public function __construct(
        public int $subscriptionId,
        public int $cycleId,
        public int $cycleNumber,
        public int $orderId,
        public int $deliveriesLeft,
    ) {
    }

    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;
    }
}
