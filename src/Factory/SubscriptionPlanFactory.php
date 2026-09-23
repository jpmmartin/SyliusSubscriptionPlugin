<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Factory;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Webmozart\Assert\Assert;

/** A plan only exists on a variant, so the admin creates it from the variant it belongs to. */
final class SubscriptionPlanFactory implements SubscriptionPlanFactoryInterface
{
    /** @param FactoryInterface<object> $decorated */
    public function __construct(private readonly FactoryInterface $decorated)
    {
    }

    public function createNew(): SubscriptionPlanInterface
    {
        $plan = $this->decorated->createNew();
        Assert::isInstanceOf($plan, SubscriptionPlanInterface::class);

        return $plan;
    }

    public function createForVariant(ProductVariantInterface $productVariant): SubscriptionPlanInterface
    {
        $plan = $this->createNew();
        $plan->setProductVariant($productVariant);

        return $plan;
    }
}
