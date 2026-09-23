<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

use Doctrine\Common\Collections\Collection;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Model\ResourceInterface;
use Sylius\Resource\Model\TimestampableInterface;
use Sylius\Resource\Model\VersionedInterface;

/**
 * One renewal of a subscription: when it is due, the order it produced and every attempt to charge
 * that order. Cycle 1 is the initial order.
 *
 * The version is an optimistic lock: whatever advances a cycle does so only if nobody else has
 * since, which is what keeps two runs of the scheduler from creating two orders or two charges.
 */
interface SubscriptionCycleInterface extends ResourceInterface, TimestampableInterface, VersionedInterface
{
    /** Waiting for its date. */
    public const STATE_SCHEDULED = 'scheduled';

    /** Held back by a gate until a deadline. No order exists yet. */
    public const STATE_ON_HOLD = 'on_hold';

    /** Its order exists and is being charged, retried or reconciled. */
    public const STATE_AWAITING_PAYMENT = 'awaiting_payment';

    /** Final. */
    public const STATE_PAID = 'paid';

    /**
     * Its charge, its gates or its items could not be settled. The next cycle is scheduled, and an
     * administrator may still retry this one.
     */
    public const STATE_FAILED = 'failed';

    /** Final: its subscription stopped, or an administrator cancelled its order. */
    public const STATE_CANCELLED = 'cancelled';

    public function getSubscription(): ?SubscriptionInterface;

    public function setSubscription(?SubscriptionInterface $subscription): void;

    public function getNumber(): int;

    public function setNumber(int $number): void;

    public function getScheduledAt(): ?\DateTimeImmutable;

    public function setScheduledAt(?\DateTimeImmutable $scheduledAt): void;

    public function getState(): string;

    public function setState(string $state): void;

    public function getOrder(): ?OrderInterface;

    public function setOrder(?OrderInterface $order): void;

    public function getHoldUntil(): ?\DateTimeImmutable;

    public function setHoldUntil(?\DateTimeImmutable $holdUntil): void;

    public function getHoldReason(): ?string;

    public function setHoldReason(?string $holdReason): void;

    /** When the scheduler should next try to charge, retry or reconcile this cycle. */
    public function getNextAttemptAt(): ?\DateTimeImmutable;

    public function setNextAttemptAt(?\DateTimeImmutable $nextAttemptAt): void;

    /** Why the cycle failed: the last decline, the gate's reason, or none of its items being available. */
    public function getCancellationReason(): ?string;

    public function setCancellationReason(?string $cancellationReason): void;

    /** @return Collection<int, SubscriptionChargeAttemptInterface> */
    public function getAttempts(): Collection;

    public function addAttempt(SubscriptionChargeAttemptInterface $attempt): void;

    /** @return Collection<int, SubscriptionCycleItemInterface> what its latest order took in and what it skipped */
    public function getItems(): Collection;

    public function addItem(SubscriptionCycleItemInterface $item): void;

    public function removeItem(SubscriptionCycleItemInterface $item): void;

    /**
     * Set while an administrator's retry of the failed cycle is being charged: it is charged once,
     * never retried by the scheduler, and does not count as the subscription's open cycle. It is
     * cleared as soon as the cycle leaves awaiting payment.
     */
    public function isManualRetry(): bool;

    public function setManualRetry(bool $manualRetry): void;
}
