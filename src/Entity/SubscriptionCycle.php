<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Model\TimestampableTrait;

class SubscriptionCycle implements SubscriptionCycleInterface
{
    use TimestampableTrait;

    protected ?int $id = null;

    protected ?SubscriptionInterface $subscription = null;

    protected int $number = 1;

    protected ?\DateTimeImmutable $scheduledAt = null;

    protected string $state = SubscriptionCycleInterface::STATE_SCHEDULED;

    protected ?OrderInterface $order = null;

    protected ?\DateTimeImmutable $holdUntil = null;

    protected ?string $holdReason = null;

    protected ?\DateTimeImmutable $nextAttemptAt = null;

    protected ?\DateTimeImmutable $renewalNoticeAt = null;

    protected ?string $cancellationReason = null;

    protected ?int $version = 1;

    protected bool $manualRetry = false;

    protected bool $skipped = false;

    /** @var Collection<int, SubscriptionChargeAttemptInterface> */
    protected Collection $attempts;

    /** @var Collection<int, SubscriptionCycleItemInterface> */
    protected Collection $items;

    public function __construct()
    {
        $this->attempts = new ArrayCollection();
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubscription(): ?SubscriptionInterface
    {
        return $this->subscription;
    }

    public function setSubscription(?SubscriptionInterface $subscription): void
    {
        $this->subscription = $subscription;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function setNumber(int $number): void
    {
        $this->number = $number;
    }

    public function getScheduledAt(): ?\DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(?\DateTimeImmutable $scheduledAt): void
    {
        $this->scheduledAt = $scheduledAt;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): void
    {
        $this->state = $state;
        // A manual retry lasts as long as its charge: paid, failed again or cancelled, it is over.
        if (SubscriptionCycleInterface::STATE_AWAITING_PAYMENT !== $state) {
            $this->manualRetry = false;
        }
    }

    public function getOrder(): ?OrderInterface
    {
        return $this->order;
    }

    public function setOrder(?OrderInterface $order): void
    {
        $this->order = $order;
    }

    public function getHoldUntil(): ?\DateTimeImmutable
    {
        return $this->holdUntil;
    }

    public function setHoldUntil(?\DateTimeImmutable $holdUntil): void
    {
        $this->holdUntil = $holdUntil;
    }

    public function getHoldReason(): ?string
    {
        return $this->holdReason;
    }

    public function setHoldReason(?string $holdReason): void
    {
        $this->holdReason = $holdReason;
    }

    public function getRenewalNoticeAt(): ?\DateTimeImmutable
    {
        return $this->renewalNoticeAt;
    }

    public function setRenewalNoticeAt(?\DateTimeImmutable $renewalNoticeAt): void
    {
        $this->renewalNoticeAt = $renewalNoticeAt;
    }

    public function getNextAttemptAt(): ?\DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    public function setNextAttemptAt(?\DateTimeImmutable $nextAttemptAt): void
    {
        $this->nextAttemptAt = $nextAttemptAt;
    }

    public function getCancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function setCancellationReason(?string $cancellationReason): void
    {
        $this->cancellationReason = $cancellationReason;
    }

    public function getVersion(): ?int
    {
        return $this->version;
    }

    public function setVersion(?int $version): void
    {
        $this->version = $version;
    }

    public function getAttempts(): Collection
    {
        return $this->attempts;
    }

    public function addAttempt(SubscriptionChargeAttemptInterface $attempt): void
    {
        if ($this->attempts->contains($attempt)) {
            return;
        }

        $this->attempts->add($attempt);
        $attempt->setCycle($this);
    }

    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(SubscriptionCycleItemInterface $item): void
    {
        if ($this->items->contains($item)) {
            return;
        }

        $this->items->add($item);
        $item->setCycle($this);
    }

    public function removeItem(SubscriptionCycleItemInterface $item): void
    {
        if ($this->items->removeElement($item)) {
            $item->setCycle(null);
        }
    }

    public function isManualRetry(): bool
    {
        return $this->manualRetry;
    }

    public function setManualRetry(bool $manualRetry): void
    {
        $this->manualRetry = $manualRetry;
    }

    public function isSkipped(): bool
    {
        return $this->skipped;
    }

    public function setSkipped(bool $skipped): void
    {
        $this->skipped = $skipped;
    }
}
