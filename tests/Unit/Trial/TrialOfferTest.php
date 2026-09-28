<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Unit\Trial;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlan;
use JpmMartin\SyliusSubscriptionPlugin\Trial\TrialEligibilityInterface;
use JpmMartin\SyliusSubscriptionPlugin\Trial\TrialOffer;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\ProductVariantInterface;

final class TrialOfferTest extends TestCase
{
    public function testTermsWithATrialGiveItToAGuestAndToACustomerWhoMayStillHaveIt(): void
    {
        $offer = new TrialOffer($this->eligibility(true), ['CARD']);

        self::assertSame(14, $offer->trialDaysFor($this->termsWithATrialOf(14), new ProductVariant(), null));
        self::assertSame(14, $offer->trialDaysFor($this->termsWithATrialOf(14), new ProductVariant(), new Customer()));
        self::assertNull($offer->trialDaysFor($this->termsWithATrialOf(null), new ProductVariant(), new Customer()));
    }

    public function testACustomerWhoMayNoLongerHaveItGetsNone(): void
    {
        $offer = new TrialOffer($this->eligibility(false), ['CARD']);

        self::assertNull($offer->trialDaysFor($this->termsWithATrialOf(14), new ProductVariant(), new Customer()));
    }

    public function testNoTrialIsGivenWhileNoPaymentMethodCanKeepACard(): void
    {
        $offer = new TrialOffer($this->eligibility(true), []);

        self::assertNull($offer->trialDaysFor($this->termsWithATrialOf(14), new ProductVariant(), null));
    }

    private function termsWithATrialOf(?int $days): SubscriptionPlan
    {
        $plan = new SubscriptionPlan();
        $plan->setTrialDays($days);

        return $plan;
    }

    private function eligibility(bool $eligible): TrialEligibilityInterface
    {
        return new class($eligible) implements TrialEligibilityInterface {
            public function __construct(private readonly bool $eligible)
            {
            }

            public function isEligible(CustomerInterface $customer, ProductVariantInterface $variant): bool
            {
                return $this->eligible;
            }
        };
    }
}
