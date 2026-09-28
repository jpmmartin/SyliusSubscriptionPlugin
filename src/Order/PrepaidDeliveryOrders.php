<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Order;

use Sylius\Component\Order\Model\OrderInterface;

/**
 * The orders being placed for prepaid deliveries, which Sylius's checkout processes step by step before
 * their cycle points at them: their shipping is paid for by the charge of their block.
 */
final class PrepaidDeliveryOrders
{
    /** @var \WeakMap<OrderInterface, true> */
    private \WeakMap $orders;

    public function __construct()
    {
        $this->orders = new \WeakMap();
    }

    public function add(OrderInterface $order): void
    {
        $this->orders[$order] = true;
    }

    public function has(OrderInterface $order): bool
    {
        return isset($this->orders[$order]);
    }
}
