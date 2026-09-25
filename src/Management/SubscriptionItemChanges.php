<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Management;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * What the customer asks to change in a subscription's items, all at once. Nothing here is checked:
 * SubscriptionItemEditorInterface holds it against what it offers.
 */
final class SubscriptionItemChanges
{
    /** @var array<int, SubscriptionItemEdit> by the item's object id */
    private array $edits = [];

    /** @var list<SubscriptionItemAddition> */
    private array $additions = [];

    /** The item's edit, which starts as the item is: an item left alone stays as it is. */
    public function edit(SubscriptionItemInterface $item): SubscriptionItemEdit
    {
        return $this->edits[spl_object_id($item)] ??= new SubscriptionItemEdit($item);
    }

    public function add(ProductVariantInterface $variant, int $quantity): void
    {
        $this->additions[] = new SubscriptionItemAddition($variant, $quantity);
    }

    /** @return list<SubscriptionItemEdit> */
    public function edits(): array
    {
        return array_values($this->edits);
    }

    /** @return list<SubscriptionItemAddition> */
    public function additions(): array
    {
        return $this->additions;
    }

    public function changesAnything(): bool
    {
        if ([] !== $this->additions) {
            return true;
        }

        foreach ($this->edits as $edit) {
            if ($edit->changesAnything()) {
                return true;
            }
        }

        return false;
    }
}
