<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Frequency;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use Sylius\Component\Core\Model\OrderItemInterface;

/**
 * Changes what a single cart line is bought as: once, on one of the frequencies its cart offers, or on one of the
 * plans its variant offers (choose-subscription-per-cart-line). Neither method processes the cart or flushes; the
 * caller does both, the way CartRepeaterInterface's own methods already work.
 */
interface CartItemSubscriptionChangerInterface
{
    /**
     * The options this line currently has: always buying it once, plus whichever frequencies and plans apply to
     * it. Empty for an immutable line, or until the store's order item can hold a plan or a frequency.
     *
     * @return list<CartItemSubscriptionOption>
     */
    public function getOptions(OrderItemInterface $item): array;

    /**
     * @throws \InvalidArgumentException when given both a plan and a frequency, or an option this line does not
     *                                    have
     */
    public function change(OrderItemInterface $item, ?SubscriptionPlanInterface $plan, ?SubscriptionFrequencyInterface $frequency): void;
}
