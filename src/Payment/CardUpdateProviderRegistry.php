<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Payment;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;

/** The first tagged card update provider, by priority, that supports the subscription. */
final class CardUpdateProviderRegistry
{
    /** @param iterable<CardUpdateProviderInterface> $providers */
    public function __construct(private readonly iterable $providers)
    {
    }

    /** Where the customer changes the subscription's card; null when no integration lets them. */
    public function urlFor(SubscriptionInterface $subscription): ?string
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($subscription)) {
                return $provider->getUrl($subscription);
            }
        }

        return null;
    }
}
