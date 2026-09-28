<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Pricing;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Pricing\PriceIncreaseAcceptancePolicyInterface;

/**
 * The configured policy, unless a scenario asks for acceptance: it writes "required" to a file of the
 * test application, as Sylius's Behat clock does with the date, so the requests of a scenario and its
 * steps agree. PriceUpdateContext deletes the file after each scenario.
 */
final class SwitchablePriceIncreaseAcceptancePolicy implements PriceIncreaseAcceptancePolicyInterface
{
    public function __construct(
        private readonly PriceIncreaseAcceptancePolicyInterface $configuredPolicy,
        private readonly string $switchFile,
    ) {
    }

    public function requiresAcceptance(SubscriptionInterface $subscription): bool
    {
        if (is_file($this->switchFile)) {
            return 'required' === trim((string) file_get_contents($this->switchFile));
        }

        return $this->configuredPolicy->requiresAcceptance($subscription);
    }
}
