<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Resource\Model\ResourceInterface;

/**
 * One call to the renewal charger for a cycle's order, and what it answered: a charge, or a status
 * query made to settle a charge whose outcome was unknown.
 */
interface SubscriptionChargeAttemptInterface extends ResourceInterface
{
    public const TYPE_CHARGE = 'charge';

    public const TYPE_STATUS = 'status';

    /** Paid by the customer on the store's order payment page, not charged by the plugin. */
    public const TYPE_CUSTOMER = 'customer';

    public const OUTCOME_APPROVED = 'approved';

    public const OUTCOME_DECLINED = 'declined';

    /** Refused before the gateway was contacted. */
    public const OUTCOME_NOT_ATTEMPTED = 'not_attempted';

    /** The gateway did not answer: the money may or may not have been taken. */
    public const OUTCOME_UNKNOWN = 'unknown';

    public function getCycle(): ?SubscriptionCycleInterface;

    public function setCycle(?SubscriptionCycleInterface $cycle): void;

    public function getType(): string;

    public function setType(string $type): void;

    public function getOutcome(): string;

    public function setOutcome(string $outcome): void;

    public function getReason(): ?string;

    public function setReason(?string $reason): void;

    /** The gateway's own code for a decline or a charge it refused, when it gave one. */
    public function getCode(): ?string;

    public function setCode(?string $code): void;

    public function getAttemptedAt(): ?\DateTimeImmutable;

    public function setAttemptedAt(?\DateTimeImmutable $attemptedAt): void;

    public function getPayment(): ?PaymentInterface;

    public function setPayment(?PaymentInterface $payment): void;
}
