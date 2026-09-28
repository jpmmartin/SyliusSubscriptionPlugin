<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Trial;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionTermsInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * The free trial a line gets: its terms' days, when the store has a payment method that keeps a card
 * without charging it and the customer, if known yet, may still have one of that variant.
 */
final class TrialOffer
{
    /** @param list<string> $trialPaymentMethodCodes */
    public function __construct(
        private readonly TrialEligibilityInterface $eligibility,
        private readonly array $trialPaymentMethodCodes,
    ) {
    }

    public function trialDaysFor(SubscriptionTermsInterface $terms, ProductVariantInterface $variant, ?CustomerInterface $customer): ?int
    {
        $trialDays = $terms->getTrialDays();
        if (null === $trialDays || [] === $this->trialPaymentMethodCodes) {
            return null;
        }

        return null === $customer || $this->eligibility->isEligible($customer, $variant) ? $trialDays : null;
    }

    /** @return list<string> */
    public function trialPaymentMethodCodes(): array
    {
        return $this->trialPaymentMethodCodes;
    }
}
