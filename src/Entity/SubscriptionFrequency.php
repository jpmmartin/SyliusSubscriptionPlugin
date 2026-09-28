<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Resource\Model\ToggleableTrait;

class SubscriptionFrequency implements SubscriptionFrequencyInterface
{
    use ToggleableTrait;

    protected ?int $id = null;

    protected ?string $code = null;

    protected ?string $name = null;

    protected int $intervalCount = 1;

    protected SubscriptionIntervalUnit $intervalUnit = SubscriptionIntervalUnit::Month;

    protected int $discountPercentage = 0;

    protected ?int $maxCycles = null;

    protected ?int $introductoryDiscountPercentage = null;

    protected int $introductoryCycles = 1;

    protected ?int $trialDays = null;

    protected ?int $commitmentCycles = null;

    protected int $deliveriesPerCharge = 1;

    /** @var Collection<array-key, ChannelInterface> */
    protected Collection $channels;

    public function __construct()
    {
        $this->channels = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): void
    {
        $this->code = $code;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getIntervalCount(): int
    {
        return $this->intervalCount;
    }

    public function setIntervalCount(int $intervalCount): void
    {
        $this->intervalCount = $intervalCount;
    }

    public function getIntervalUnit(): SubscriptionIntervalUnit
    {
        return $this->intervalUnit;
    }

    public function setIntervalUnit(SubscriptionIntervalUnit $intervalUnit): void
    {
        $this->intervalUnit = $intervalUnit;
    }

    public function getDiscountPercentage(): int
    {
        return $this->discountPercentage;
    }

    public function setDiscountPercentage(int $discountPercentage): void
    {
        $this->discountPercentage = $discountPercentage;
    }

    public function getMaxCycles(): ?int
    {
        return $this->maxCycles;
    }

    public function setMaxCycles(?int $maxCycles): void
    {
        $this->maxCycles = $maxCycles;
    }

    public function getIntroductoryDiscountPercentage(): ?int
    {
        return $this->introductoryDiscountPercentage;
    }

    public function setIntroductoryDiscountPercentage(?int $introductoryDiscountPercentage): void
    {
        $this->introductoryDiscountPercentage = $introductoryDiscountPercentage;
    }

    public function getIntroductoryCycles(): int
    {
        return $this->introductoryCycles;
    }

    public function setIntroductoryCycles(int $introductoryCycles): void
    {
        $this->introductoryCycles = $introductoryCycles;
    }

    public function getTrialDays(): ?int
    {
        return $this->trialDays;
    }

    public function setTrialDays(?int $trialDays): void
    {
        $this->trialDays = $trialDays;
    }

    public function getCommitmentCycles(): ?int
    {
        return $this->commitmentCycles;
    }

    public function setCommitmentCycles(?int $commitmentCycles): void
    {
        $this->commitmentCycles = $commitmentCycles;
    }

    public function getDeliveriesPerCharge(): int
    {
        return $this->deliveriesPerCharge;
    }

    public function setDeliveriesPerCharge(int $deliveriesPerCharge): void
    {
        $this->deliveriesPerCharge = $deliveriesPerCharge;
    }

    public function getChannels(): Collection
    {
        return $this->channels;
    }

    public function hasChannel(ChannelInterface $channel): bool
    {
        return $this->channels->contains($channel);
    }

    public function addChannel(ChannelInterface $channel): void
    {
        if (!$this->hasChannel($channel)) {
            $this->channels->add($channel);
        }
    }

    public function removeChannel(ChannelInterface $channel): void
    {
        $this->channels->removeElement($channel);
    }
}
