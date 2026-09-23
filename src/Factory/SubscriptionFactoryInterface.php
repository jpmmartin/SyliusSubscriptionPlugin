<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Factory;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Resource\Factory\FactoryInterface;

/** @extends FactoryInterface<SubscriptionInterface> */
interface SubscriptionFactoryInterface extends FactoryInterface
{
    public function createNew(): SubscriptionInterface;

    /**
     * A pending subscription for subscription lines of one placed order whose plans share an interval,
     * with the order's customer, channel, methods and consent, and an item per line, in their order,
     * with the line's price frozen.
     *
     * @param non-empty-list<OrderItemInterface> $orderItems
     */
    public function createFromOrderItems(array $orderItems): SubscriptionInterface;
}
