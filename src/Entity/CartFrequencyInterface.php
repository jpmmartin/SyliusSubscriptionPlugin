<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Model\ResourceInterface;

/**
 * The frequency a customer chose to repeat a cart with. It belongs to the cart, not to its lines: a
 * line added later is repeated too, since the cart's lines are given the frequency each time Sylius
 * processes the cart.
 */
interface CartFrequencyInterface extends ResourceInterface
{
    public function getOrder(): ?OrderInterface;

    public function setOrder(?OrderInterface $order): void;

    public function getFrequency(): ?SubscriptionFrequencyInterface;

    public function setFrequency(?SubscriptionFrequencyInterface $frequency): void;
}
