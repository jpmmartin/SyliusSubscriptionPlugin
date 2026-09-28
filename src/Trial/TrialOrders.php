<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Trial;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use Sylius\Component\Core\Model\OrderInterface;

/**
 * Tells the orders whose only charge is a free trial's: nothing to pay, yet a payment of 0 is kept so
 * the gateway authorizes it and keeps the card the renewals will be charged to.
 */
final class TrialOrders
{
    public function keepsAZeroPayment(OrderInterface $order): bool
    {
        return 0 === $order->getTotal() && $this->hasATrial($order);
    }

    public function hasATrial(OrderInterface $order): bool
    {
        foreach ($order->getItems() as $item) {
            if ($item instanceof SubscriptionPlanAwareInterface && null !== $item->getSubscriptionTrialDays()) {
                return true;
            }
        }

        return false;
    }
}
