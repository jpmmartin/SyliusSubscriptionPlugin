<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Entity;

use Sylius\Component\Channel\Model\ChannelsAwareInterface;
use Sylius\Resource\Model\CodeAwareInterface;
use Sylius\Resource\Model\ResourceInterface;
use Sylius\Resource\Model\ToggleableInterface;

/**
 * One of the store's own frequencies, such as "every month, 5% off", with which a customer repeats a
 * whole cart instead of subscribing product by product. Only the variants marked as repeatable are
 * repeated with it. Disabling a frequency stops it being offered; subscriptions already on it keep
 * renewing.
 */
interface SubscriptionFrequencyInterface extends ResourceInterface, CodeAwareInterface, ToggleableInterface, ChannelsAwareInterface, SubscriptionTermsInterface
{
    public function setName(?string $name): void;

    public function setIntervalCount(int $intervalCount): void;

    public function setIntervalUnit(SubscriptionIntervalUnit $intervalUnit): void;

    public function setDiscountPercentage(int $discountPercentage): void;

    public function setMaxCycles(?int $maxCycles): void;
}
