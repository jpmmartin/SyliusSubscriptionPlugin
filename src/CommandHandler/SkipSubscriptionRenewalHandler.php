<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\CommandHandler;

use JpmMartin\SyliusSubscriptionPlugin\Command\SkipSubscriptionRenewal;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionRenewalSkipperInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
use Webmozart\Assert\Assert;

/** Whoever dispatches it has checked who may skip; the skipper still refuses a renewal that cannot be skipped. */
final class SkipSubscriptionRenewalHandler
{
    /** @param SubscriptionRepositoryInterface<SubscriptionInterface> $subscriptionRepository */
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly SubscriptionRenewalSkipperInterface $skipper,
    ) {
    }

    public function __invoke(SkipSubscriptionRenewal $command): void
    {
        $subscription = $this->subscriptionRepository->find($command->subscriptionId);
        Assert::isInstanceOf($subscription, SubscriptionInterface::class, 'The subscription does not exist.');

        $this->skipper->skip($subscription);
    }
}
