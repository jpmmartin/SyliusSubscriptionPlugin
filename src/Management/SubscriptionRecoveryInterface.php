<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Management;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use Sylius\Component\Core\Model\OrderInterface;

/**
 * A customer recovering their subscription suspended after cycles failed in a row: its last failed
 * cycle is retried with a new order the customer pays on the store's order payment page, and paying it
 * reactivates the subscription. The plugin never charges that order: left unpaid, it expires like any
 * unpaid order, and the cycle fails again. A suspension by an administrator is theirs to lift.
 */
interface SubscriptionRecoveryInterface
{
    /** None of the subscription's items still renewing can be sold now. */
    public const NOTHING_TO_RENEW = 'nothing_to_renew';

    /**
     * Whether its customer can recover it now: it was suspended after failed cycles, and either its last
     * cycle failed and something of it can be sold, or a recovery is already waiting to be paid.
     */
    public function canRecover(SubscriptionInterface $subscription): bool;

    /**
     * Why a subscription suspended after failed cycles cannot be recovered now, one of the constants;
     * null when it can, or when it was not suspended that way.
     */
    public function whyNot(SubscriptionInterface $subscription): ?string;

    /**
     * The order the customer pays to recover it: the last failed cycle retried with a new order of what
     * can be sold now, not charged; or the one of a recovery started before and not paid yet.
     *
     * @throws \InvalidArgumentException when it cannot be recovered now
     */
    public function start(SubscriptionInterface $subscription): OrderInterface;

    /** Whether the cycle is a recovery its customer started and has not paid yet. */
    public function isAwaitingItsCustomer(SubscriptionCycleInterface $cycle): bool;
}
