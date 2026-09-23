<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Twig;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Lets the variant pages list the plans a variant offers without a controller of their own. */
final class SubscriptionPlanExtension extends AbstractExtension
{
    /** @param RepositoryInterface<SubscriptionPlanInterface> $subscriptionPlanRepository */
    public function __construct(private readonly RepositoryInterface $subscriptionPlanRepository)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('jpm_martin_sylius_subscription_variant_plans', $this->getVariantPlans(...)),
        ];
    }

    /** @return list<SubscriptionPlanInterface> */
    public function getVariantPlans(ProductVariantInterface $productVariant): array
    {
        if (null === $productVariant->getId()) {
            return [];
        }

        /** @var list<SubscriptionPlanInterface> $plans */
        $plans = $this->subscriptionPlanRepository->findBy(['productVariant' => $productVariant], ['id' => 'ASC']);

        return $plans;
    }
}
