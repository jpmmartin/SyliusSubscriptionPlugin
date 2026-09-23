<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Frequency;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use Sylius\Component\Core\Model\OrderInterface;

/**
 * "Repeat this cart": the frequency a customer chose for a whole cart. The choice belongs to the
 * cart; its lines are given the frequency each time Sylius processes the cart, so a caller that
 * changes the choice processes the cart and flushes afterwards, as with any other cart change.
 */
interface CartRepeaterInterface
{
    /** The frequency the cart is repeated with, including a choice made in this request and not flushed yet. */
    public function getFrequency(OrderInterface $cart): ?SubscriptionFrequencyInterface;

    /** @throws \InvalidArgumentException when the frequency is not offered to the cart */
    public function repeat(OrderInterface $cart, SubscriptionFrequencyInterface $frequency): void;

    public function stopRepeating(OrderInterface $cart): void;

    /**
     * The enabled frequencies of the cart's channel, in the order the store created them.
     *
     * @return list<SubscriptionFrequencyInterface>
     */
    public function getOfferedFrequencies(OrderInterface $cart): array;

    public function isOffered(OrderInterface $cart, SubscriptionFrequencyInterface $frequency): bool;

    /** Whether the cart has a one-off line that repeating it would renew: a variant that can be repeated, without a plan. */
    public function hasRepeatableLines(OrderInterface $cart): bool;
}
