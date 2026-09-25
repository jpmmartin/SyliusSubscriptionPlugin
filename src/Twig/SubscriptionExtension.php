<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Twig;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionAddressChangerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionCycleRetrierInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionFrequencyChangerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionRenewalSkipperInterface;
use JpmMartin\SyliusSubscriptionPlugin\Order\RenewalAddressesResolver;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionInterval;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionSchedulerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SubscriptionExtension extends AbstractExtension
{
    public function __construct(
        private readonly SubscriptionFrequencyChangerInterface $frequencyChanger,
        private readonly SubscriptionCycleRetrierInterface $cycleRetrier,
        private readonly SubscriptionSchedulerInterface $scheduler,
        private readonly SubscriptionRenewalSkipperInterface $renewalSkipper,
        private readonly SubscriptionAddressChangerInterface $addressChanger,
        private readonly RenewalAddressesResolver $addressesResolver,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('jpm_martin_sylius_subscription_open_cycle', $this->getOpenCycle(...)),
            new TwigFunction('jpm_martin_sylius_subscription_frequencies_to_change_to', $this->getFrequenciesToChangeTo(...)),
            new TwigFunction('jpm_martin_sylius_subscription_can_retry_cycle', $this->canRetryCycle(...)),
            new TwigFunction('jpm_martin_sylius_subscription_renewal_to_skip', $this->getRenewalToSkip(...)),
            new TwigFunction('jpm_martin_sylius_subscription_can_change_address', $this->canChangeAddress(...)),
            new TwigFunction('jpm_martin_sylius_subscription_renewal_shipping_address', $this->addressesResolver->shippingAddress(...)),
            new TwigFunction('jpm_martin_sylius_subscription_renewal_billing_address', $this->addressesResolver->billingAddress(...)),
        ];
    }

    /** The cycle that is next to be charged, whose date is the next renewal. */
    public function getOpenCycle(SubscriptionInterface $subscription): ?SubscriptionCycleInterface
    {
        return $this->scheduler->findOpenCycle($subscription);
    }

    /** @return list<SubscriptionInterval> */
    public function getFrequenciesToChangeTo(SubscriptionInterface $subscription): array
    {
        return $this->frequencyChanger->frequenciesToChangeTo($subscription);
    }

    public function canRetryCycle(SubscriptionCycleInterface $cycle): bool
    {
        return $this->cycleRetrier->canRetry($cycle);
    }

    public function canChangeAddress(SubscriptionInterface $subscription): bool
    {
        return $this->addressChanger->canChange($subscription);
    }

    /** The renewal the customer can skip now, whose date the skip button shows; null when none can. */
    public function getRenewalToSkip(SubscriptionInterface $subscription): ?SubscriptionCycleInterface
    {
        return $this->renewalSkipper->renewalToSkip($subscription);
    }
}
