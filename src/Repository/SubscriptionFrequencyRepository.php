<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Repository;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Channel\Model\ChannelInterface;

/**
 * @template T of SubscriptionFrequencyInterface
 * @implements SubscriptionFrequencyRepositoryInterface<T>
 */
class SubscriptionFrequencyRepository extends EntityRepository implements SubscriptionFrequencyRepositoryInterface
{
    public function findEnabledByChannel(ChannelInterface $channel): array
    {
        /** @var list<SubscriptionFrequencyInterface> $frequencies */
        $frequencies = $this->createQueryBuilder('o')
            ->andWhere('o.enabled = true')
            ->andWhere(':channel MEMBER OF o.channels')
            ->setParameter('channel', $channel)
            ->addOrderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        return $frequencies;
    }
}
