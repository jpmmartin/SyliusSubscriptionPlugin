<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Trial;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

/** One free trial per customer and variant: whoever had a subscription with it, in any state, pays from the start. */
final class SubscriptionHistoryTrialEligibility implements TrialEligibilityInterface
{
    /** @param SubscriptionRepositoryInterface<SubscriptionInterface> $subscriptionRepository */
    public function __construct(private readonly SubscriptionRepositoryInterface $subscriptionRepository)
    {
    }

    public function isEligible(CustomerInterface $customer, ProductVariantInterface $variant): bool
    {
        return null === $customer->getId() || !$this->subscriptionRepository->existsWithCustomerAndVariant($customer, $variant);
    }
}
