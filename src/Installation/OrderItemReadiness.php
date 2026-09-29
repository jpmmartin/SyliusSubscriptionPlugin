<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Installation;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;

/** Reads the order item class Sylius is configured with, as its resources do. */
final class OrderItemReadiness implements OrderItemReadinessInterface
{
    public function __construct(private readonly string $orderItemClass)
    {
    }

    public function isReady(): bool
    {
        return is_a($this->orderItemClass, SubscriptionPlanAwareInterface::class, true);
    }

    public function orderItemClass(): string
    {
        return $this->orderItemClass;
    }
}
