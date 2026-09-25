<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Order;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;

/**
 * Where a subscription's next renewal goes: to its own addresses once its customer or an administrator
 * changed them, and to its last order's until then. The renewal and the pages that show the addresses
 * ask the same question here.
 */
final class RenewalAddressesResolver
{
    public function shippingAddress(SubscriptionInterface $subscription): ?AddressInterface
    {
        return $subscription->getShippingAddress() ?? $this->lastOrderOf($subscription)?->getShippingAddress();
    }

    public function billingAddress(SubscriptionInterface $subscription): ?AddressInterface
    {
        return $subscription->getBillingAddress() ?? $this->lastOrderOf($subscription)?->getBillingAddress();
    }

    /** The order of its latest cycle that has one: the initial order until a renewal is placed. */
    public function lastOrderOf(SubscriptionInterface $subscription): ?OrderInterface
    {
        $lastOrder = null;
        $lastNumber = 0;
        foreach ($subscription->getCycles() as $cycle) {
            if (null !== $cycle->getOrder() && $cycle->getNumber() > $lastNumber) {
                $lastOrder = $cycle->getOrder();
                $lastNumber = $cycle->getNumber();
            }
        }

        return $lastOrder;
    }
}
