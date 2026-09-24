<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\OptimisticLockException;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\OrderInterface;

/**
 * @template T of SubscriptionCycleInterface
 * @implements SubscriptionCycleRepositoryInterface<T>
 */
class SubscriptionCycleRepository extends EntityRepository implements SubscriptionCycleRepositoryInterface
{
    public function findDue(\DateTimeImmutable $now): array
    {
        /** @var list<array{id: int|string, version: int|string}> $rows */
        $rows = $this->createQueryBuilder('o')
            ->select('o.id AS id', 'o.version AS version')
            ->innerJoin('o.subscription', 'subscription')
            // An administrator's retry is still reconciled while its subscription is suspended.
            ->andWhere('subscription.state = :active OR (subscription.state = :suspended AND o.manualRetry = true)')
            ->andWhere('(o.state = :scheduled AND o.scheduledAt <= :now) OR o.state = :onHold OR (o.state = :awaitingPayment AND o.nextAttemptAt <= :now)')
            ->setParameter('active', SubscriptionInterface::STATE_ACTIVE)
            ->setParameter('suspended', SubscriptionInterface::STATE_SUSPENDED)
            ->setParameter('scheduled', SubscriptionCycleInterface::STATE_SCHEDULED)
            ->setParameter('onHold', SubscriptionCycleInterface::STATE_ON_HOLD)
            ->setParameter('awaitingPayment', SubscriptionCycleInterface::STATE_AWAITING_PAYMENT)
            ->setParameter('now', $now)
            ->addOrderBy('o.scheduledAt', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->getQuery()
            ->getArrayResult()
        ;

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'version' => (int) $row['version']], $rows);
    }

    public function findToAnnounce(\DateTimeImmutable $now, \DateTimeImmutable $until): array
    {
        /** @var list<array{id: int|string, version: int|string}> $rows */
        $rows = $this->createQueryBuilder('o')
            ->select('o.id AS id', 'o.version AS version')
            ->innerJoin('o.subscription', 'subscription')
            ->andWhere('subscription.state = :active')
            ->andWhere('o.state = :scheduled')
            ->andWhere('o.scheduledAt > :now')
            ->andWhere('o.scheduledAt <= :until')
            ->andWhere('o.renewalNoticeAt IS NULL')
            ->setParameter('active', SubscriptionInterface::STATE_ACTIVE)
            ->setParameter('scheduled', SubscriptionCycleInterface::STATE_SCHEDULED)
            ->setParameter('now', $now)
            ->setParameter('until', $until)
            ->addOrderBy('o.scheduledAt', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->getQuery()
            ->getArrayResult()
        ;

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'version' => (int) $row['version']], $rows);
    }

    public function findUnchangedSince(int $id, int $version): ?SubscriptionCycleInterface
    {
        try {
            $cycle = $this->find($id, LockMode::OPTIMISTIC, $version);
        } catch (OptimisticLockException) {
            return null;
        }

        return $cycle instanceof SubscriptionCycleInterface ? $cycle : null;
    }

    public function findOneByOrder(OrderInterface $order): ?SubscriptionCycleInterface
    {
        $cycle = $this->findOneBy(['order' => $order]);

        return $cycle instanceof SubscriptionCycleInterface ? $cycle : null;
    }
}
