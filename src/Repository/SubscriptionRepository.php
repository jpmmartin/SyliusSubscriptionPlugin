<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Repository;

use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * The joins on items would repeat a subscription of several items, but the ORM hydrates each
 * subscription once, and the grid's paginator counts them distinct.
 *
 * @template T of SubscriptionInterface
 * @implements SubscriptionRepositoryInterface<T>
 */
class SubscriptionRepository extends EntityRepository implements SubscriptionRepositoryInterface
{
    public function findByInitialOrder(OrderInterface $order): array
    {
        /** @var list<SubscriptionInterface> $subscriptions */
        $subscriptions = $this->createQueryBuilder('o')
            ->innerJoin('o.items', 'item')
            ->innerJoin('item.originOrderItem', 'originOrderItem')
            ->andWhere('originOrderItem.order = :order')
            ->setParameter('order', $order)
            ->orderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        return $subscriptions;
    }

    public function findActiveByProductVariant(ProductVariantInterface $productVariant): array
    {
        /** @var list<SubscriptionInterface> $subscriptions */
        $subscriptions = $this->createQueryBuilder('o')
            ->innerJoin('o.items', 'item')
            ->andWhere('item.productVariant = :productVariant')
            ->andWhere('item.removedAt IS NULL')
            ->andWhere('o.state = :active')
            ->setParameter('productVariant', $productVariant)
            ->setParameter('active', SubscriptionInterface::STATE_ACTIVE)
            ->orderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        return $subscriptions;
    }

    public function findByCustomer(CustomerInterface $customer): array
    {
        /** @var list<SubscriptionInterface> $subscriptions */
        $subscriptions = $this->createQueryBuilder('o')
            ->andWhere('o.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('o.id', 'DESC')
            ->getQuery()
            ->getResult()
        ;

        return $subscriptions;
    }

    public function createAdminListQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->innerJoin('o.customer', 'customer')
            ->innerJoin('o.items', 'item')
            ->innerJoin('item.productVariant', 'productVariant')
            ->leftJoin('o.cycles', 'openCycle', Join::WITH, 'openCycle.state IN (:openCycleStates) AND openCycle.manualRetry = false')
            ->setParameter('openCycleStates', [
                SubscriptionCycleInterface::STATE_SCHEDULED,
                SubscriptionCycleInterface::STATE_ON_HOLD,
                SubscriptionCycleInterface::STATE_AWAITING_PAYMENT,
            ])
        ;
    }

    public function findOneByIdAndCustomer(int|string $id, ?CustomerInterface $customer): ?SubscriptionInterface
    {
        if (null === $customer) {
            return null;
        }

        $subscription = $this->findOneBy(['id' => (int) $id, 'customer' => $customer]);

        return $subscription instanceof SubscriptionInterface ? $subscription : null;
    }
}
