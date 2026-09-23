<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Shop\Account\Subscription;

use FriendsOfBehat\PageObjectExtension\Page\SymfonyPage;

final class IndexPage extends SymfonyPage
{
    public function getRouteName(): string
    {
        return 'jpm_martin_sylius_subscription_shop_account_subscription_index';
    }

    public function hasSubscriptionTo(string $productName): bool
    {
        return $this->hasElement('subscription', ['%product%' => $productName]);
    }

    /** One column of the subscription's row: products, frequency, price, state or next-renewal. */
    public function getColumnOf(string $productName, string $column): string
    {
        $cell = $this->getElement('subscription', ['%product%' => $productName])->find('css', \sprintf('[data-test-%s]', $column));

        return null === $cell ? '' : trim($cell->getText());
    }

    /** One part of an item in the subscription's row: quantity, unit-price or variant. */
    public function getItemOf(string $subscriptionProduct, string $itemProduct, string $part): string
    {
        $item = $this->getElement('subscription', ['%product%' => $subscriptionProduct])->find('css', \sprintf('[data-test-item="%s"]', $itemProduct));
        $cell = $item?->find('css', 'variant' === $part ? '[data-test-variant]' : \sprintf('[data-test-item-%s]', $part));

        return null === $cell ? '' : trim($cell->getText());
    }

    public function showSubscriptionTo(string $productName): void
    {
        $this->getElement('subscription', ['%product%' => $productName])->find('css', '[data-test-show]')?->click();
    }

    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            // A subscription is found by any of its products.
            'subscription' => '[data-test-subscription*="%product%"]',
        ]);
    }
}
