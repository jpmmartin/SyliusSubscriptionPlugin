<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Frequency;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;

/**
 * One of the three kinds of option a cart line can be given (choose-subscription-per-cart-line): buying it once,
 * when both are null; one of the frequencies the cart offers; or one of the plans its variant offers. Never both a
 * plan and a frequency.
 */
final class CartItemSubscriptionOption
{
    public function __construct(
        public readonly ?SubscriptionPlanInterface $plan,
        public readonly ?SubscriptionFrequencyInterface $frequency,
        public readonly bool $current,
    ) {
    }

    /** What a form posts for this option, and how the shop API names it: "", "plan:<code>" or "frequency:<code>". */
    public function value(): string
    {
        if (null !== $this->plan) {
            return 'plan:' . (string) $this->plan->getCode();
        }

        if (null !== $this->frequency) {
            return 'frequency:' . (string) $this->frequency->getCode();
        }

        return '';
    }
}
