<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Payment;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Payment\CardUpdateProviderInterface;

/** A gateway integration of the test store that lets customers change the card of "Card on file" only. */
final class TestCardUpdateProvider implements CardUpdateProviderInterface
{
    public function supports(SubscriptionInterface $subscription): bool
    {
        return 'Card_on_file' === $subscription->getPaymentMethod()?->getCode();
    }

    public function getUrl(SubscriptionInterface $subscription): string
    {
        return \sprintf('https://gateway.example.com/cards/update?subscription=%d', (int) $subscription->getId());
    }
}
