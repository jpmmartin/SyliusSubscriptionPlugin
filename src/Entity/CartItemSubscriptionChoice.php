<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

use Sylius\Component\Core\Model\OrderItemInterface;

class CartItemSubscriptionChoice implements CartItemSubscriptionChoiceInterface
{
    protected ?int $id = null;

    protected ?OrderItemInterface $orderItem = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderItem(): ?OrderItemInterface
    {
        return $this->orderItem;
    }

    public function setOrderItem(?OrderItemInterface $orderItem): void
    {
        $this->orderItem = $orderItem;
    }
}
