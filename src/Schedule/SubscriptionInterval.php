<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Schedule;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionTermsInterface;
use Webmozart\Assert\Assert;

/** How often a subscription renews: "every 3 months" is 3 of Month. */
final class SubscriptionInterval
{
    public function __construct(
        public readonly int $count,
        public readonly SubscriptionIntervalUnit $unit,
    ) {
        Assert::greaterThan($count, 0);
    }

    /** The interval of a plan or of a frequency. */
    public static function of(SubscriptionTermsInterface $terms): self
    {
        return new self($terms->getIntervalCount(), $terms->getIntervalUnit());
    }

    /** "3-month", unique per interval, for keys and form values. */
    public function key(): string
    {
        return $this->count . '-' . $this->unit->value;
    }

    public function equals(self $interval): bool
    {
        return $this->count === $interval->count && $this->unit === $interval->unit;
    }
}
