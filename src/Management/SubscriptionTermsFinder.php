<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Management;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionTermsInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionFrequencyRepositoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionInterval;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

/**
 * What a variant can renew on, one set of terms per interval: the oldest enabled plan of the variant,
 * and the oldest enabled store frequency offered in the channel. The frequency change and the item
 * changes ask here, so they agree on what an interval offers.
 */
final class SubscriptionTermsFinder
{
    /**
     * @param RepositoryInterface<SubscriptionPlanInterface> $planRepository
     * @param SubscriptionFrequencyRepositoryInterface<SubscriptionFrequencyInterface> $frequencyRepository
     */
    public function __construct(
        private readonly RepositoryInterface $planRepository,
        private readonly SubscriptionFrequencyRepositoryInterface $frequencyRepository,
    ) {
    }

    /** @return array<string, SubscriptionPlanInterface> by interval key */
    public function plansOf(ProductVariantInterface $variant): array
    {
        return self::byInterval($this->planRepository->findBy(['productVariant' => $variant, 'enabled' => true], ['id' => 'ASC']));
    }

    /** @return list<SubscriptionPlanInterface> the oldest enabled plan of the interval of each variant that has one */
    public function plansWith(SubscriptionInterval $interval): array
    {
        $plans = $this->planRepository->findBy(
            ['enabled' => true, 'intervalCount' => $interval->count, 'intervalUnit' => $interval->unit],
            ['id' => 'ASC'],
        );

        $byVariant = [];
        foreach ($plans as $plan) {
            $variant = $plan->getProductVariant();
            if (null !== $variant) {
                $byVariant[spl_object_id($variant)] ??= $plan;
            }
        }

        return array_values($byVariant);
    }

    /** @return array<string, SubscriptionFrequencyInterface> by interval key */
    public function frequenciesOf(ChannelInterface $channel): array
    {
        return self::byInterval($this->frequencyRepository->findEnabledByChannel($channel));
    }

    /**
     * @template T of SubscriptionTermsInterface
     *
     * @param iterable<T> $terms oldest first
     *
     * @return array<string, T> the oldest for each interval
     */
    private static function byInterval(iterable $terms): array
    {
        $byInterval = [];
        foreach ($terms as $candidate) {
            $byInterval[SubscriptionInterval::of($candidate)->key()] ??= $candidate;
        }

        return $byInterval;
    }
}
