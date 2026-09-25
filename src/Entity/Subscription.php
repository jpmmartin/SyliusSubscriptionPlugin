<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Resource\Model\TimestampableTrait;

class Subscription implements SubscriptionInterface
{
    use TimestampableTrait;

    protected ?int $id = null;

    protected ?CustomerInterface $customer = null;

    protected ?ChannelInterface $channel = null;

    protected ?string $currencyCode = null;

    protected int $billingIntervalCount = 1;

    protected SubscriptionIntervalUnit $billingIntervalUnit = SubscriptionIntervalUnit::Month;

    protected int $deliveryIntervalCount = 1;

    protected SubscriptionIntervalUnit $deliveryIntervalUnit = SubscriptionIntervalUnit::Month;

    protected ?PaymentMethodInterface $paymentMethod = null;

    protected ?ShippingMethodInterface $shippingMethod = null;

    protected ?AddressInterface $shippingAddress = null;

    protected ?AddressInterface $billingAddress = null;

    protected ?string $consentVersion = null;

    protected ?string $consentText = null;

    protected ?\DateTimeImmutable $consentAcceptedAt = null;

    protected ?\DateTimeImmutable $activatedAt = null;

    protected ?\DateTimeImmutable $scheduleAnchorAt = null;

    protected int $scheduleAnchorCycle = 1;

    protected string $state = SubscriptionInterface::STATE_PENDING;

    protected int $consecutiveFailedCycles = 0;

    protected bool $suspendedForUnpaidRenewals = false;

    /** @var Collection<int, SubscriptionItemInterface> */
    protected Collection $items;

    /** @var Collection<int, SubscriptionCycleInterface> */
    protected Collection $cycles;

    public function __construct()
    {
        $this->cycles = new ArrayCollection();
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): ?CustomerInterface
    {
        return $this->customer;
    }

    public function setCustomer(?CustomerInterface $customer): void
    {
        $this->customer = $customer;
    }

    public function getChannel(): ?ChannelInterface
    {
        return $this->channel;
    }

    public function setChannel(?ChannelInterface $channel): void
    {
        $this->channel = $channel;
    }

    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(?string $currencyCode): void
    {
        $this->currencyCode = $currencyCode;
    }

    public function getBillingIntervalCount(): int
    {
        return $this->billingIntervalCount;
    }

    public function setBillingIntervalCount(int $billingIntervalCount): void
    {
        $this->billingIntervalCount = $billingIntervalCount;
    }

    public function getBillingIntervalUnit(): SubscriptionIntervalUnit
    {
        return $this->billingIntervalUnit;
    }

    public function setBillingIntervalUnit(SubscriptionIntervalUnit $billingIntervalUnit): void
    {
        $this->billingIntervalUnit = $billingIntervalUnit;
    }

    public function getDeliveryIntervalCount(): int
    {
        return $this->deliveryIntervalCount;
    }

    public function setDeliveryIntervalCount(int $deliveryIntervalCount): void
    {
        $this->deliveryIntervalCount = $deliveryIntervalCount;
    }

    public function getDeliveryIntervalUnit(): SubscriptionIntervalUnit
    {
        return $this->deliveryIntervalUnit;
    }

    public function setDeliveryIntervalUnit(SubscriptionIntervalUnit $deliveryIntervalUnit): void
    {
        $this->deliveryIntervalUnit = $deliveryIntervalUnit;
    }

    public function getPaymentMethod(): ?PaymentMethodInterface
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(?PaymentMethodInterface $paymentMethod): void
    {
        $this->paymentMethod = $paymentMethod;
    }

    public function getShippingMethod(): ?ShippingMethodInterface
    {
        return $this->shippingMethod;
    }

    public function setShippingMethod(?ShippingMethodInterface $shippingMethod): void
    {
        $this->shippingMethod = $shippingMethod;
    }

    public function getShippingAddress(): ?AddressInterface
    {
        return $this->shippingAddress;
    }

    public function setShippingAddress(?AddressInterface $shippingAddress): void
    {
        $this->shippingAddress = $shippingAddress;
    }

    public function getBillingAddress(): ?AddressInterface
    {
        return $this->billingAddress;
    }

    public function setBillingAddress(?AddressInterface $billingAddress): void
    {
        $this->billingAddress = $billingAddress;
    }

    public function getConsentVersion(): ?string
    {
        return $this->consentVersion;
    }

    public function setConsentVersion(?string $consentVersion): void
    {
        $this->consentVersion = $consentVersion;
    }

    public function getConsentText(): ?string
    {
        return $this->consentText;
    }

    public function setConsentText(?string $consentText): void
    {
        $this->consentText = $consentText;
    }

    public function getConsentAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->consentAcceptedAt;
    }

    public function setConsentAcceptedAt(?\DateTimeImmutable $consentAcceptedAt): void
    {
        $this->consentAcceptedAt = $consentAcceptedAt;
    }

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function setActivatedAt(?\DateTimeImmutable $activatedAt): void
    {
        $this->activatedAt = $activatedAt;
    }

    public function getScheduleAnchorAt(): ?\DateTimeImmutable
    {
        return $this->scheduleAnchorAt;
    }

    public function setScheduleAnchorAt(?\DateTimeImmutable $scheduleAnchorAt): void
    {
        $this->scheduleAnchorAt = $scheduleAnchorAt;
    }

    public function getScheduleAnchorCycle(): int
    {
        return $this->scheduleAnchorCycle;
    }

    public function setScheduleAnchorCycle(int $scheduleAnchorCycle): void
    {
        $this->scheduleAnchorCycle = $scheduleAnchorCycle;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): void
    {
        $this->state = $state;
    }

    public function getConsecutiveFailedCycles(): int
    {
        return $this->consecutiveFailedCycles;
    }

    public function setConsecutiveFailedCycles(int $consecutiveFailedCycles): void
    {
        $this->consecutiveFailedCycles = $consecutiveFailedCycles;
    }

    public function isSuspendedForUnpaidRenewals(): bool
    {
        return $this->suspendedForUnpaidRenewals;
    }

    public function setSuspendedForUnpaidRenewals(bool $suspendedForUnpaidRenewals): void
    {
        $this->suspendedForUnpaidRenewals = $suspendedForUnpaidRenewals;
    }

    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(SubscriptionItemInterface $item): void
    {
        if ($this->items->contains($item)) {
            return;
        }

        $this->items->add($item);
        $item->setSubscription($this);
    }

    public function getRenewalTotal(): int
    {
        $total = 0;
        foreach ($this->items as $item) {
            if ($item->isRenewable()) {
                $total += $item->getUnitPrice() * $item->getQuantity();
            }
        }

        return $total;
    }

    public function getCycles(): Collection
    {
        return $this->cycles;
    }

    public function addCycle(SubscriptionCycleInterface $cycle): void
    {
        if ($this->cycles->contains($cycle)) {
            return;
        }

        $this->cycles->add($cycle);
        $cycle->setSubscription($this);
    }
}
