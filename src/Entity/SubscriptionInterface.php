<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

use Doctrine\Common\Collections\Collection;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Resource\Model\ResourceInterface;
use Sylius\Resource\Model\TimestampableInterface;

/**
 * What a customer receives on one schedule: one or more items, each a quantity of a variant at the
 * price frozen when they subscribed, all renewed together by one order per cycle, until they cancel
 * or no item has cycles left.
 *
 * The billing and delivery intervals are kept apart even though today they are always equal, so a
 * prepaid plan (charge once, deliver several times) can be added without reshaping the data.
 */
interface SubscriptionInterface extends ResourceInterface, TimestampableInterface
{
    /** Created with its initial order, waiting for that order to be paid. */
    public const STATE_PENDING = 'pending';

    public const STATE_ACTIVE = 'active';

    /** Paused by its customer: generates no cycles until it is resumed. */
    public const STATE_PAUSED = 'paused';

    /** Generates no cycles until an administrator reactivates it. */
    public const STATE_SUSPENDED = 'suspended';

    /** Final. */
    public const STATE_CANCELLED = 'cancelled';

    /** Final: none of its items has cycles left under its plan's maximum. */
    public const STATE_COMPLETED = 'completed';

    public function getCustomer(): ?CustomerInterface;

    public function setCustomer(?CustomerInterface $customer): void;

    public function getChannel(): ?ChannelInterface;

    public function setChannel(?ChannelInterface $channel): void;

    public function getCurrencyCode(): ?string;

    public function setCurrencyCode(?string $currencyCode): void;

    public function getBillingIntervalCount(): int;

    public function setBillingIntervalCount(int $billingIntervalCount): void;

    public function getBillingIntervalUnit(): SubscriptionIntervalUnit;

    public function setBillingIntervalUnit(SubscriptionIntervalUnit $billingIntervalUnit): void;

    public function getDeliveryIntervalCount(): int;

    public function setDeliveryIntervalCount(int $deliveryIntervalCount): void;

    public function getDeliveryIntervalUnit(): SubscriptionIntervalUnit;

    public function setDeliveryIntervalUnit(SubscriptionIntervalUnit $deliveryIntervalUnit): void;

    public function getPaymentMethod(): ?PaymentMethodInterface;

    public function setPaymentMethod(?PaymentMethodInterface $paymentMethod): void;

    /** Null when none of its items requires shipping. */
    public function getShippingMethod(): ?ShippingMethodInterface;

    public function setShippingMethod(?ShippingMethodInterface $shippingMethod): void;

    /**
     * The addresses its customer or an administrator chose for its renewals: copies of their own, which
     * editing the address book leaves alone. Null until they are changed: the renewals then take those of
     * the subscription's last order.
     */
    public function getShippingAddress(): ?AddressInterface;

    public function setShippingAddress(?AddressInterface $shippingAddress): void;

    public function getBillingAddress(): ?AddressInterface;

    public function setBillingAddress(?AddressInterface $billingAddress): void;

    public function getConsentVersion(): ?string;

    public function setConsentVersion(?string $consentVersion): void;

    public function getConsentText(): ?string;

    public function setConsentText(?string $consentText): void;

    public function getConsentAcceptedAt(): ?\DateTimeImmutable;

    public function setConsentAcceptedAt(?\DateTimeImmutable $consentAcceptedAt): void;

    /** When the initial order was paid. */
    public function getActivatedAt(): ?\DateTimeImmutable;

    public function setActivatedAt(?\DateTimeImmutable $activatedAt): void;

    /**
     * The calendar is anchored on this date and cycle number: cycle n is scheduled at the anchor
     * plus (n - anchor cycle) billing intervals, so a late charge never shifts the cycles after it.
     */
    public function getScheduleAnchorAt(): ?\DateTimeImmutable;

    public function setScheduleAnchorAt(?\DateTimeImmutable $scheduleAnchorAt): void;

    public function getScheduleAnchorCycle(): int;

    public function setScheduleAnchorCycle(int $scheduleAnchorCycle): void;

    public function getState(): string;

    public function setState(string $state): void;

    /** Cycles failed in a row since the last one charged or the last reactivation. */
    public function getConsecutiveFailedCycles(): int;

    public function setConsecutiveFailedCycles(int $consecutiveFailedCycles): void;

    /**
     * Whether it was suspended because its renewals could not be charged, so its customer can recover
     * it by paying: the cycles failed in a row, and the last on a charge that was declined or could not
     * be attempted. Not when a gate, an expired hold or nothing to renew failed that cycle, nor when an
     * administrator suspended it. Cleared when it is reactivated.
     */
    public function isSuspendedForUnpaidRenewals(): bool;

    public function setSuspendedForUnpaidRenewals(bool $suspendedForUnpaidRenewals): void;

    /** @return Collection<int, SubscriptionItemInterface> */
    public function getItems(): Collection;

    public function addItem(SubscriptionItemInterface $item): void;

    /**
     * What the items still renewing cost per renewal at their frozen prices, before the taxes, shipping
     * charges and promotions of each renewal order.
     */
    public function getRenewalTotal(): int;

    /** @return Collection<int, SubscriptionCycleInterface> */
    public function getCycles(): Collection;

    public function addCycle(SubscriptionCycleInterface $cycle): void;
}
