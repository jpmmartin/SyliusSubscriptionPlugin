<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Payment;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionChargeAttemptInterface;

/**
 * What the renewal charger answered for one payment: approved; declined, with the issuer's reason;
 * not attempted, with the reason it refused before contacting the gateway; or unknown, when the
 * gateway did not answer and the money may or may not have been taken.
 *
 * A failure may also carry the gateway's own code for it, such as "insufficient_funds". The plugin
 * gives it no meaning of its own: a retry policy decides what it means.
 */
final class ChargeOutcome
{
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $reason = null,
        public readonly ?string $code = null,
    ) {
    }

    public static function approved(): self
    {
        return new self(SubscriptionChargeAttemptInterface::OUTCOME_APPROVED);
    }

    public static function declined(?string $reason = null, ?string $code = null): self
    {
        return new self(SubscriptionChargeAttemptInterface::OUTCOME_DECLINED, $reason, $code);
    }

    public static function notAttempted(string $reason, ?string $code = null): self
    {
        return new self(SubscriptionChargeAttemptInterface::OUTCOME_NOT_ATTEMPTED, $reason, $code);
    }

    public static function unknown(?string $reason = null): self
    {
        return new self(SubscriptionChargeAttemptInterface::OUTCOME_UNKNOWN, $reason);
    }

    public function isApproved(): bool
    {
        return SubscriptionChargeAttemptInterface::OUTCOME_APPROVED === $this->outcome;
    }

    public function isUnknown(): bool
    {
        return SubscriptionChargeAttemptInterface::OUTCOME_UNKNOWN === $this->outcome;
    }

    /** Declined or not attempted: a failure the retry policy applies to. */
    public function isFailure(): bool
    {
        return \in_array($this->outcome, [
            SubscriptionChargeAttemptInterface::OUTCOME_DECLINED,
            SubscriptionChargeAttemptInterface::OUTCOME_NOT_ATTEMPTED,
        ], true);
    }
}
