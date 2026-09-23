<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Gate;

/** What one gate says about a due cycle: it passes, it waits until a date, or it is rejected. */
final class GateDecision
{
    private const PASS = 'pass';

    private const WAIT = 'wait';

    private const REJECT = 'reject';

    private function __construct(
        private readonly string $verdict,
        public readonly ?\DateTimeImmutable $until = null,
        public readonly ?string $reason = null,
    ) {
    }

    public static function pass(): self
    {
        return new self(self::PASS);
    }

    /** The cycle is held, and re-evaluated on every run, until $until at the latest. */
    public static function wait(\DateTimeImmutable $until, string $reason): self
    {
        return new self(self::WAIT, $until, $reason);
    }

    public static function reject(string $reason): self
    {
        return new self(self::REJECT, null, $reason);
    }

    public function passes(): bool
    {
        return self::PASS === $this->verdict;
    }

    public function waits(): bool
    {
        return self::WAIT === $this->verdict;
    }

    public function rejects(): bool
    {
        return self::REJECT === $this->verdict;
    }
}
