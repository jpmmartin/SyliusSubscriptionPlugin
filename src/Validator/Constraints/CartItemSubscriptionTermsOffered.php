<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * A cart line's subscription can be changed only to one of its own options: compra única, one of its variant's
 * enabled plans, or one of the frequencies the cart's channel offers to a variant that can be repeated - never
 * both a plan and a frequency at once (choose-subscription-per-cart-line).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class CartItemSubscriptionTermsOffered extends Constraint
{
    public string $bothMessage = 'jpm_martin_sylius_subscription.cart_item_subscription_terms.both_given';

    public string $notOfferedMessage = 'jpm_martin_sylius_subscription.cart_item_subscription_terms.not_offered';

    public function validatedBy(): string
    {
        return CartItemSubscriptionTermsOfferedValidator::class;
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
