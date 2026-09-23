<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/** A cart can be repeated only with a frequency its channel offers: enabled and available in it. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class SubscriptionFrequencyOffered extends Constraint
{
    public string $message = 'jpm_martin_sylius_subscription.cart_frequency.not_offered';

    public function validatedBy(): string
    {
        return SubscriptionFrequencyOfferedValidator::class;
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
