<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Shop\Checkout;

use FriendsOfBehat\PageObjectExtension\Page\SymfonyPage;

/** The recurring-charge consent on Sylius's last checkout step, and what that step answers. */
final class SubscriptionConsentPage extends SymfonyPage
{
    public function getRouteName(): string
    {
        return 'sylius_shop_checkout_complete';
    }

    public function acceptConsent(): void
    {
        $this->getElement('consent_checkbox')->check();
    }

    public function showsMessage(string $message): bool
    {
        return str_contains($this->getDocument()->getText(), $message);
    }

    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            'consent_checkbox' => '[data-test-subscription-consent-checkbox]',
        ]);
    }
}
