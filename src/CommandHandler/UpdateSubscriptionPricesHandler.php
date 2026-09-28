<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\CommandHandler;

use JpmMartin\SyliusSubscriptionPlugin\Command\UpdateSubscriptionPrices;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Pricing\PriceUpdateTarget;
use JpmMartin\SyliusSubscriptionPlugin\Pricing\SubscriptionPriceUpdaterInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;

/** A subscription gone meanwhile has nothing left to reprice. */
final class UpdateSubscriptionPricesHandler
{
    /** @param SubscriptionRepositoryInterface<SubscriptionInterface> $subscriptionRepository */
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly SubscriptionPriceUpdaterInterface $priceUpdater,
    ) {
    }

    public function __invoke(UpdateSubscriptionPrices $command): void
    {
        $subscription = $this->subscriptionRepository->find($command->subscriptionId);
        if (!$subscription instanceof SubscriptionInterface) {
            return;
        }

        $this->priceUpdater->update($subscription, new PriceUpdateTarget($command->targetType, $command->targetId));
    }
}
