<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\StateProvider\Shop;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionFrequencyRepositoryInterface;
use Sylius\Bundle\ApiBundle\Serializer\ContextKeys;
use Sylius\Component\Channel\Model\ChannelInterface;

/**
 * GET /api/v2/shop/subscription-frequencies: the frequencies a cart of the request's channel can be
 * repeated with, the same the cart page offers.
 *
 * @implements ProviderInterface<SubscriptionFrequencyInterface>
 */
final class SubscriptionFrequencyCollectionProvider implements ProviderInterface
{
    /** @param SubscriptionFrequencyRepositoryInterface<SubscriptionFrequencyInterface> $frequencyRepository */
    public function __construct(private readonly SubscriptionFrequencyRepositoryInterface $frequencyRepository)
    {
    }

    /** @return list<SubscriptionFrequencyInterface> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $channel = $context[ContextKeys::CHANNEL] ?? null;
        if (!$channel instanceof ChannelInterface) {
            return [];
        }

        return $this->frequencyRepository->findEnabledByChannel($channel);
    }
}
