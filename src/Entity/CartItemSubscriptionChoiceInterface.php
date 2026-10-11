<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Resource\Model\ResourceInterface;

/**
 * Marks a cart line whose subscription was chosen on the line itself, rather than inherited from
 * "repeat this cart". The chosen frequency is not stored here: it already lives on the line
 * (`subscriptionFrequency`, from the trait the store's order item already carries). This row only
 * says that whatever is there, including nothing for a one-off purchase, is the customer's own
 * choice, so the cart's frequency processor must leave it alone.
 */
interface CartItemSubscriptionChoiceInterface extends ResourceInterface
{
    public function getOrderItem(): ?OrderItemInterface;

    public function setOrderItem(?OrderItemInterface $orderItem): void;
}
