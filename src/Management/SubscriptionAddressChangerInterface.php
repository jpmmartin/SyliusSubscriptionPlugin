<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Management;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;

/**
 * Where a subscription's renewals go. The new addresses apply from the next renewal whose order is not
 * placed yet; one already placed keeps its own.
 */
interface SubscriptionAddressChangerInterface
{
    /** A subscription that is neither cancelled nor completed. */
    public function canChange(SubscriptionInterface $subscription): bool;

    /** Whether any of its items is shipped: if none is, there is no shipping method to choose. */
    public function requiresShipping(SubscriptionInterface $subscription): bool;

    /**
     * The shipping methods the checkout would offer its items at $shippingAddress, cheapest first, with
     * what each would cost its next renewal.
     *
     * @return list<ShippingMethodOffer>
     */
    public function shippingMethodsFor(SubscriptionInterface $subscription, AddressInterface $shippingAddress): array;

    /**
     * Keeps copies of $shippingAddress and of $billingAddress, or of the shipping address again when it is
     * null. $shippingMethod replaces the subscription's; it is required when the current one does not reach
     * the new address, and refused when it does not reach it itself.
     *
     * @throws \InvalidArgumentException when the subscription cannot be changed, no shipping method reaches
     *                                   the address, or the method given or kept does not
     */
    public function change(
        SubscriptionInterface $subscription,
        AddressInterface $shippingAddress,
        ?AddressInterface $billingAddress = null,
        ?ShippingMethodInterface $shippingMethod = null,
    ): void;
}
