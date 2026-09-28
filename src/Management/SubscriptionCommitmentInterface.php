<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Management;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;

/**
 * Whether a subscription is still within the minimum commitment of an item: until then, its customer
 * cannot cancel it, pause it or remove that item. Only paid cycles count; a skipped, failed or
 * cancelled one does not. Replace it to count a commitment another way, in months for instance.
 */
interface SubscriptionCommitmentInterface
{
    public function isCommitted(SubscriptionInterface $subscription): bool;

    /** The most cycles one of its items still has to be paid before the commitment is met; 0 when none. */
    public function remainingCycles(SubscriptionInterface $subscription): int;

    public function isItemCommitted(SubscriptionItemInterface $item): bool;
}
