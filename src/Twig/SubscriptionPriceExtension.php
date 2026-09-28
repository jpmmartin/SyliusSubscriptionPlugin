<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Twig;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionTermsInterface;
use JpmMartin\SyliusSubscriptionPlugin\OrderProcessing\SubscriptionPlanPriceProcessor;
use JpmMartin\SyliusSubscriptionPlugin\Trial\TrialOffer;
use Sylius\Component\Core\Calculator\ProductVariantPricesCalculatorInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** What subscribing to a variant costs, for the product page and the cart. */
final class SubscriptionPriceExtension extends AbstractExtension
{
    public function __construct(
        private readonly ProductVariantPricesCalculatorInterface $productVariantPricesCalculator,
        private readonly TrialOffer $trialOffer,
        private readonly CustomerContextInterface $customerContext,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('jpm_martin_sylius_subscription_introductory_offer', $this->getIntroductoryOffer(...)),
            new TwigFunction('jpm_martin_sylius_subscription_trial_offer', $this->getTrialOffer(...)),
            new TwigFunction('jpm_martin_sylius_subscription_trials_offered', fn (): bool => [] !== $this->trialOffer->trialPaymentMethodCodes()),
        ];
    }

    /**
     * The introductory price of the variant on the terms, how many cycles it lasts and the price after,
     * worked out as the cart does, in the channel's base currency; null when the terms have none.
     *
     * @return array{introductory_price: int, cycles: int, price: int}|null
     */
    public function getIntroductoryOffer(ProductVariantInterface $variant, SubscriptionTermsInterface $terms, ChannelInterface $channel): ?array
    {
        $introductoryDiscount = $terms->getIntroductoryDiscountPercentage();
        if (null === $introductoryDiscount) {
            return null;
        }

        $price = $this->productVariantPricesCalculator->calculate($variant, ['channel' => $channel]);

        return [
            'introductory_price' => SubscriptionPlanPriceProcessor::applyDiscount($price, $introductoryDiscount),
            'cycles' => $terms->getIntroductoryCycles(),
            'price' => SubscriptionPlanPriceProcessor::applyDiscount($price, $terms->getDiscountPercentage()),
        ];
    }

    /**
     * The free trial the current customer, or a visitor, gets of the variant on the terms, and the price
     * after it, in the channel's base currency; null when there is none to get.
     *
     * @return array{days: int, price: int}|null
     */
    public function getTrialOffer(ProductVariantInterface $variant, SubscriptionTermsInterface $terms, ChannelInterface $channel): ?array
    {
        $customer = $this->customerContext->getCustomer();
        $days = $this->trialOffer->trialDaysFor($terms, $variant, $customer instanceof CustomerInterface ? $customer : null);
        if (null === $days) {
            return null;
        }

        return [
            'days' => $days,
            'price' => SubscriptionPlanPriceProcessor::applyDiscount(
                $this->productVariantPricesCalculator->calculate($variant, ['channel' => $channel]),
                $terms->getDiscountPercentage(),
            ),
        ];
    }
}
