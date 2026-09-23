<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

use Sylius\Resource\Model\ResourceInterface;

/**
 * What a cycle did with one item of its subscription: took it into its order, at that quantity and
 * price, or skipped it for the reason given. Items that no longer renew are not recorded.
 */
interface SubscriptionCycleItemInterface extends ResourceInterface
{
    public const SKIPPED_OUT_OF_STOCK = 'out_of_stock';

    /** The variant or its product is disabled. */
    public const SKIPPED_DISABLED = 'disabled';

    public const SKIPPED_NOT_IN_CHANNEL = 'not_in_channel';

    public function getCycle(): ?SubscriptionCycleInterface;

    public function setCycle(?SubscriptionCycleInterface $cycle): void;

    public function getSubscriptionItem(): ?SubscriptionItemInterface;

    public function setSubscriptionItem(?SubscriptionItemInterface $subscriptionItem): void;

    public function getQuantity(): int;

    public function setQuantity(int $quantity): void;

    public function getUnitPrice(): int;

    public function setUnitPrice(int $unitPrice): void;

    /** One of the SKIPPED_* reasons, or null for an item the order carries. */
    public function getSkippedReason(): ?string;

    public function setSkippedReason(?string $skippedReason): void;

    public function isIncluded(): bool;
}
