<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

use Sylius\Component\Core\Model\OrderInterface;

class CartFrequency implements CartFrequencyInterface
{
    protected ?int $id = null;

    protected ?OrderInterface $order = null;

    protected ?SubscriptionFrequencyInterface $frequency = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): ?OrderInterface
    {
        return $this->order;
    }

    public function setOrder(?OrderInterface $order): void
    {
        $this->order = $order;
    }

    public function getFrequency(): ?SubscriptionFrequencyInterface
    {
        return $this->frequency;
    }

    public function setFrequency(?SubscriptionFrequencyInterface $frequency): void
    {
        $this->frequency = $frequency;
    }
}
