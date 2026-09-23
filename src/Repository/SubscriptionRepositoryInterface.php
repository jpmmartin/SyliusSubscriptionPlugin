<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

/**
 * @template T of SubscriptionInterface
 * @extends RepositoryInterface<T>
 */
interface SubscriptionRepositoryInterface extends RepositoryInterface
{
    /** @return list<SubscriptionInterface> the subscriptions the order's lines started */
    public function findByInitialOrder(OrderInterface $order): array;

    /** @return list<SubscriptionInterface> the active subscriptions with an item of the variant */
    public function findActiveByProductVariant(ProductVariantInterface $productVariant): array;

    /** @return list<SubscriptionInterface> the customer's subscriptions, the latest first */
    public function findByCustomer(CustomerInterface $customer): array;

    /**
     * For the admin grid. The open cycle is joined, not selected, under the "openCycle" alias, so the
     * grid can filter and sort on the next renewal without loading a partial list of cycles; the
     * items' variants are joined under "productVariant", so it can filter on them.
     */
    public function createAdminListQueryBuilder(): QueryBuilder;

    /** Null for a subscription of anyone else, so a customer can never reach another's. */
    public function findOneByIdAndCustomer(int|string $id, ?CustomerInterface $customer): ?SubscriptionInterface;
}
