<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Pricing;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;
use Webmozart\Assert\Assert;

/** Whose subscription items a price update reprices: those on a plan, on a store frequency, or of a variant. */
final readonly class PriceUpdateTarget
{
    public const PLAN = 'plan';

    public const FREQUENCY = 'frequency';

    public const VARIANT = 'variant';

    public function __construct(
        public string $type,
        public int $id,
    ) {
        Assert::oneOf($type, [self::PLAN, self::FREQUENCY, self::VARIANT]);
    }

    public function matches(SubscriptionItemInterface $item): bool
    {
        $id = match ($this->type) {
            self::PLAN => $item->getPlan()?->getId(),
            self::FREQUENCY => $item->getFrequency()?->getId(),
            default => $item->getProductVariant()?->getId(),
        };

        return $this->id === $id;
    }

    /** The property of an item that points at the target, for queries. */
    public function itemField(): string
    {
        return match ($this->type) {
            self::PLAN => 'plan',
            self::FREQUENCY => 'frequency',
            default => 'productVariant',
        };
    }
}
