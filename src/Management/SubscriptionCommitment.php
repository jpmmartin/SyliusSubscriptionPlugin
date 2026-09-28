<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Management;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;

/** Counts each item's paid cycles, the initial order included, against the commitment it was subscribed with. */
final class SubscriptionCommitment implements SubscriptionCommitmentInterface
{
    public function isCommitted(SubscriptionInterface $subscription): bool
    {
        return 0 < $this->remainingCycles($subscription);
    }

    public function remainingCycles(SubscriptionInterface $subscription): int
    {
        $remaining = 0;
        foreach ($subscription->getItems() as $item) {
            $remaining = max($remaining, self::remainingCyclesOf($item));
        }

        return $remaining;
    }

    public function isItemCommitted(SubscriptionItemInterface $item): bool
    {
        return 0 < self::remainingCyclesOf($item);
    }

    /** A removed item commits to nothing more: only an administrator can have removed it while committed. */
    private static function remainingCyclesOf(SubscriptionItemInterface $item): int
    {
        $commitmentCycles = $item->getCommitmentCycles();
        if (null === $commitmentCycles || $item->isRemoved()) {
            return 0;
        }

        return max(0, $commitmentCycles - $item->getPaidCycles());
    }
}
