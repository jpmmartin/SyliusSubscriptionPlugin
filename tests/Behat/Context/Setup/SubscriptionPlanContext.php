<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup;

use Behat\Behat\Context\Context;
use Behat\Step\Given;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use JpmMartin\SyliusSubscriptionPlugin\Factory\SubscriptionPlanFactoryInterface;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

final class SubscriptionPlanContext implements Context
{
    /** @param RepositoryInterface<SubscriptionPlanInterface> $subscriptionPlanRepository */
    public function __construct(
        private readonly SubscriptionPlanFactoryInterface $subscriptionPlanFactory,
        private readonly RepositoryInterface $subscriptionPlanRepository,
        private readonly SharedStorageInterface $sharedStorage,
    ) {
    }

    #[Given('/^the ("[^"]+" variant) offers a "([^"]+)" subscription plan renewing every (\d+) (day|week|month|year)s?(?: with a (\d+)% discount)?$/')]
    public function theVariantOffersASubscriptionPlan(
        ProductVariantInterface $variant,
        string $code,
        string $intervalCount,
        string $intervalUnit,
        string $discountPercentage = '0',
    ): void {
        $plan = $this->subscriptionPlanFactory->createForVariant($variant);
        $plan->setCode($code);
        $plan->setName($code);
        $plan->setIntervalCount((int) $intervalCount);
        $plan->setIntervalUnit(SubscriptionIntervalUnit::from($intervalUnit));
        $plan->setDiscountPercentage((int) $discountPercentage);

        $this->subscriptionPlanRepository->add($plan);
        $this->sharedStorage->set('subscription_plan', $plan);
    }

    #[Given('/^the "([^"]+)" subscription plan is disabled$/')]
    public function theSubscriptionPlanIsDisabled(string $code): void
    {
        $plan = $this->subscriptionPlanRepository->findOneBy(['code' => $code]);
        if (!$plan instanceof SubscriptionPlanInterface) {
            throw new \InvalidArgumentException(\sprintf('There is no "%s" subscription plan.', $code));
        }

        $plan->disable();
        $this->subscriptionPlanRepository->add($plan);
    }
}
