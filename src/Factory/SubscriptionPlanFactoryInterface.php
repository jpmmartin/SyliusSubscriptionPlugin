<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Factory;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Factory\FactoryInterface;

/** @extends FactoryInterface<SubscriptionPlanInterface> */
interface SubscriptionPlanFactoryInterface extends FactoryInterface
{
    public function createNew(): SubscriptionPlanInterface;

    public function createForVariant(ProductVariantInterface $productVariant): SubscriptionPlanInterface;
}
