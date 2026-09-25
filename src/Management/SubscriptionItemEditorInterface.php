<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Management;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;

/**
 * The customer's changes to a subscription's items: a quantity, another variant of the same product,
 * an item removed, a product added. They apply from the open cycle, so only while it has no order: one
 * awaiting payment keeps the items it was placed with. Nothing reserves stock: a renewal still skips
 * what it cannot sell that day.
 */
interface SubscriptionItemEditorInterface
{
    /** Whether its items can be changed now: it is active and its open cycle has no order yet. */
    public function canEdit(SubscriptionInterface $subscription): bool;

    /**
     * The items the customer can change: those still renewing. One that reached its maximum of cycles,
     * or was removed, stays as it is.
     *
     * @return list<SubscriptionItemInterface>
     */
    public function editableItems(SubscriptionInterface $subscription): array;

    /** The highest quantity of an item, the one Sylius allows on a cart line. */
    public function maxQuantity(): int;

    /**
     * The other variants of the item's product it can move to: those the channel sells, with terms of
     * the subscription's interval of the item's kind (a plan of the variant for an item on a plan, the
     * store's frequency, if the variant can be repeated, for one on a frequency), whose maximum of
     * cycles the item's paid cycles have not reached, and that no other renewing item has.
     *
     * @return list<SubscriptionItemOffer>
     */
    public function variantsFor(SubscriptionItemInterface $item): array;

    /** Whether the item can be removed: another item would still renew. */
    public function canRemove(SubscriptionItemInterface $item): bool;

    /**
     * The variants that can be added: those the channel sells with a plan of the subscription's
     * interval, or else that can be repeated with the store's frequency of that interval, and that no
     * renewing item has. The plan wins when a variant has both.
     *
     * @return list<SubscriptionItemOffer> by product name, then by variant
     */
    public function variantsToAdd(SubscriptionInterface $subscription): array;

    /**
     * What each renewal would cost with the changes, as getRenewalTotal() counts it.
     *
     * @throws \InvalidArgumentException when the changes are not all offered now
     */
    public function renewalTotalAfter(SubscriptionInterface $subscription, SubscriptionItemChanges $changes): int;

    /**
     * Whether the changes raise what each renewal costs, so the customer must accept the recurring
     * charges again before they are applied.
     *
     * @throws \InvalidArgumentException when the changes are not all offered now
     */
    public function requiresConsent(SubscriptionInterface $subscription, SubscriptionItemChanges $changes): bool;

    /**
     * Applies the changes: a quantity keeps the frozen price; a variant moves to its offer's terms and
     * price and keeps the item's paid cycles; a removed item stays, no longer renewing; an added one is
     * frozen at its offer's price, with no cycle paid. When they raise the renewal total, the consent
     * the customer accepted, in the language given, replaces the one the subscription kept.
     *
     * @param string|null $acceptedConsentLocaleCode the language of the consent text the customer accepted,
     *     or null if they were not asked to
     *
     * @return bool false when the changes leave every item as it is
     *
     * @throws \InvalidArgumentException when the changes are not all offered now, or raise the renewal
     *     total without the consent accepted
     */
    public function apply(SubscriptionInterface $subscription, SubscriptionItemChanges $changes, ?string $acceptedConsentLocaleCode = null): bool;
}
