<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Management;

use Sylius\Component\Core\Model\ShippingMethodInterface;

/** A shipping method that reaches an address, with what it would cost the next renewal at today's prices. */
final readonly class ShippingMethodOffer
{
    public function __construct(
        public ShippingMethodInterface $method,
        public int $cost,
    ) {
    }
}
