<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Repository;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

/**
 * @template T of SubscriptionCycleInterface
 * @extends RepositoryInterface<T>
 */
interface SubscriptionCycleRepositoryInterface extends RepositoryInterface
{
    /**
     * The open cycles of active subscriptions that have something to do now: scheduled ones whose
     * date has come, held ones, which are asked again on every run, and those awaiting payment whose
     * next attempt is due; and an administrator's retry to reconcile, even in a suspended subscription.
     * Read with their version, so each is processed only if nothing changed it since.
     *
     * @return list<array{id: int, version: int}>
     */
    public function findDue(\DateTimeImmutable $now): array;

    /** The cycle, unless it was changed since it was read at $version: then someone else has dealt with it. */
    public function findUnchangedSince(int $id, int $version): ?SubscriptionCycleInterface;

    public function findOneByOrder(OrderInterface $order): ?SubscriptionCycleInterface;
}
