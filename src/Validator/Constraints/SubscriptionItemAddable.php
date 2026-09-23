<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * A subscription line can be added only for a variant the cart's channel sells, on an enabled plan
 * of that same variant.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class SubscriptionItemAddable extends Constraint
{
    public string $productVariantNotAvailableMessage = 'jpm_martin_sylius_subscription.subscription_item.product_variant.not_available';

    public string $subscriptionPlanNotOfferedMessage = 'jpm_martin_sylius_subscription.subscription_item.subscription_plan.not_offered';

    public function validatedBy(): string
    {
        return SubscriptionItemAddableValidator::class;
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
