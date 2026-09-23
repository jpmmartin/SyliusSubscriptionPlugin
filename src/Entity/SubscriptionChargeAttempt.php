<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

use Sylius\Component\Core\Model\PaymentInterface;

class SubscriptionChargeAttempt implements SubscriptionChargeAttemptInterface
{
    protected ?int $id = null;

    protected ?SubscriptionCycleInterface $cycle = null;

    protected string $type = SubscriptionChargeAttemptInterface::TYPE_CHARGE;

    protected string $outcome = SubscriptionChargeAttemptInterface::OUTCOME_UNKNOWN;

    protected ?string $reason = null;

    protected ?string $code = null;

    protected ?\DateTimeImmutable $attemptedAt = null;

    protected ?PaymentInterface $payment = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCycle(): ?SubscriptionCycleInterface
    {
        return $this->cycle;
    }

    public function setCycle(?SubscriptionCycleInterface $cycle): void
    {
        $this->cycle = $cycle;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    public function setOutcome(string $outcome): void
    {
        $this->outcome = $outcome;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): void
    {
        $this->reason = $reason;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): void
    {
        $this->code = $code;
    }

    public function getAttemptedAt(): ?\DateTimeImmutable
    {
        return $this->attemptedAt;
    }

    public function setAttemptedAt(?\DateTimeImmutable $attemptedAt): void
    {
        $this->attemptedAt = $attemptedAt;
    }

    public function getPayment(): ?PaymentInterface
    {
        return $this->payment;
    }

    public function setPayment(?PaymentInterface $payment): void
    {
        $this->payment = $payment;
    }
}
