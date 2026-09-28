<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Pricing;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use Webmozart\Assert\Assert;

/** `price_increase_acceptance`, the same for every subscription: "notice" or "required". */
final class ConfiguredPriceIncreaseAcceptancePolicy implements PriceIncreaseAcceptancePolicyInterface
{
    public function __construct(private readonly string $acceptance)
    {
        Assert::oneOf($acceptance, ['notice', 'required']);
    }

    public function requiresAcceptance(SubscriptionInterface $subscription): bool
    {
        return 'required' === $this->acceptance;
    }
}
