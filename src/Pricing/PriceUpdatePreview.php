<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Pricing;

/**
 * What a price update would do, counted by subscription: one goes up when an increase would be
 * announced for any of its items, down when none would and a price would drop, and stays the same
 * otherwise.
 */
final readonly class PriceUpdatePreview
{
    /** @param list<int> $subscriptionIds every subscription with an item the update reprices */
    public function __construct(
        public int $increases,
        public int $decreases,
        public int $unchanged,
        public array $subscriptionIds,
    ) {
    }

    public function total(): int
    {
        return $this->increases + $this->decreases + $this->unchanged;
    }
}
