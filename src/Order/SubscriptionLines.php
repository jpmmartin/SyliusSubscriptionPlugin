<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Order;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;

/**
 * The lines of an order that start a subscription: those added on a plan, and those repeated with the
 * frequency of their cart.
 */
final class SubscriptionLines
{
    /** @return list<OrderItemInterface&SubscriptionPlanAwareInterface> */
    public static function of(OrderInterface $order): array
    {
        $lines = [];
        foreach ($order->getItems() as $item) {
            if ($item instanceof SubscriptionPlanAwareInterface && null !== $item->getSubscriptionTerms()) {
                $lines[] = $item;
            }
        }

        return $lines;
    }

    public static function existIn(OrderInterface $order): bool
    {
        return [] !== self::of($order);
    }
}
