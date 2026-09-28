<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\StateProvider\Shop;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use JpmMartin\SyliusSubscriptionPlugin\Api\LoggedInCustomer;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;

/**
 * The signed-in customer's subscriptions, found as their account finds them: another customer's is not
 * found, so it answers 404 like the account.
 *
 * @implements ProviderInterface<SubscriptionInterface>
 */
final class SubscriptionProvider implements ProviderInterface
{
    /** @param SubscriptionRepositoryInterface<SubscriptionInterface> $subscriptionRepository */
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly LoggedInCustomer $loggedInCustomer,
    ) {
    }

    /** @return list<SubscriptionInterface>|SubscriptionInterface|null */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array|SubscriptionInterface|null
    {
        $customer = $this->loggedInCustomer->get();

        if ($operation instanceof CollectionOperationInterface) {
            return null === $customer ? [] : $this->subscriptionRepository->findByCustomer($customer);
        }

        $id = $uriVariables['id'] ?? null;
        if (!\is_scalar($id) || null === $customer) {
            return null;
        }

        return $this->subscriptionRepository->findOneByIdAndCustomer((string) $id, $customer);
    }
}
