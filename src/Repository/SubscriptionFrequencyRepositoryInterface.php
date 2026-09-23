<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Repository;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

/**
 * @template T of SubscriptionFrequencyInterface
 * @extends RepositoryInterface<T>
 */
interface SubscriptionFrequencyRepositoryInterface extends RepositoryInterface
{
    /**
     * The frequencies offered in the channel: enabled and available in it, in the order the store
     * created them.
     *
     * @return list<SubscriptionFrequencyInterface>
     */
    public function findEnabledByChannel(ChannelInterface $channel): array;
}
