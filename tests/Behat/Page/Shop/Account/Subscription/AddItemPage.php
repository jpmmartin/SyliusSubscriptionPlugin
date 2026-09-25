<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Shop\Account\Subscription;

use FriendsOfBehat\PageObjectExtension\Page\SymfonyPage;

/** The "Add a product" page of a subscription. */
final class AddItemPage extends SymfonyPage
{
    public function getRouteName(): string
    {
        return 'jpm_martin_sylius_subscription_shop_account_subscription_add_item';
    }

    /** Chooses the variant by the name its option starts with: "Honey · $5.00". */
    public function chooseProduct(string $name): void
    {
        $select = $this->getElement('product');
        foreach ($select->findAll('css', 'option') as $option) {
            if (str_starts_with(trim($option->getText()), $name . ' ·')) {
                $select->selectOption((string) $option->getAttribute('value'));

                return;
            }
        }

        throw new \InvalidArgumentException(\sprintf('"%s" cannot be added.', $name));
    }

    public function setQuantity(int $quantity): void
    {
        $this->getElement('quantity')->setValue((string) $quantity);
    }

    public function acceptConsent(): void
    {
        $this->getElement('consent')->check();
    }

    /** The consent with its validation message, if any. */
    public function getConsentText(): string
    {
        return trim($this->getElement('consent_row')->getText());
    }

    public function add(): void
    {
        $this->getElement('add')->press();
    }

    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            'add' => '[data-test-confirm-item-addition]',
            'consent' => '[data-test-subscription-consent-checkbox]',
            'consent_row' => '[data-test-subscription-consent]',
            'product' => '[data-test-product]',
            'quantity' => '[data-test-quantity]',
        ]);
    }
}
