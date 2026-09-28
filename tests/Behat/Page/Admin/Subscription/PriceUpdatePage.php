<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Admin\Subscription;

use FriendsOfBehat\PageObjectExtension\Page\SymfonyPage;

/** The preview of "Update subscription prices" of a plan, a frequency or a variant, and its confirmation. */
final class PriceUpdatePage extends SymfonyPage
{
    public function getRouteName(): string
    {
        return 'jpm_martin_sylius_subscription_admin_price_update';
    }

    /** One of "increases", "decreases" and "unchanged". */
    public function getCount(string $kind): int
    {
        return (int) trim($this->getElement('count', ['%kind%' => $kind])->getText());
    }

    public function confirm(): void
    {
        $this->getElement('confirm')->press();
    }

    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            'confirm' => '[data-test-confirm-price-update]',
            'count' => '[data-test-price-update-count="%kind%"]',
        ]);
    }
}
