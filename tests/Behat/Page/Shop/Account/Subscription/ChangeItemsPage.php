<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Shop\Account\Subscription;

use Behat\Mink\Element\NodeElement;
use FriendsOfBehat\PageObjectExtension\Page\SymfonyPage;

/** The "Change items" page of a subscription: one row per item it can change, by product. */
final class ChangeItemsPage extends SymfonyPage
{
    public function getRouteName(): string
    {
        return 'jpm_martin_sylius_subscription_shop_account_subscription_change_items';
    }

    public function changeQuantity(string $productName, int $quantity): void
    {
        $this->fieldOf($productName, 'quantity')->setValue((string) $quantity);
    }

    /** Chooses the variant by the name its option starts with: "Decaf · $22.50". */
    public function chooseVariant(string $productName, string $variantName): void
    {
        $select = $this->fieldOf($productName, 'variant');
        foreach ($select->findAll('css', 'option') as $option) {
            if (str_starts_with(trim($option->getText()), $variantName . ' ·')) {
                $select->selectOption((string) $option->getAttribute('value'));

                return;
            }
        }

        throw new \InvalidArgumentException(\sprintf('"%s" cannot move to "%s".', $productName, $variantName));
    }

    public function remove(string $productName): void
    {
        $this->fieldOf($productName, 'remove')->check();
    }

    public function isConsentAsked(): bool
    {
        return $this->hasElement('consent');
    }

    public function getNewRenewalTotal(): string
    {
        return trim($this->getElement('new_renewal_total')->getText());
    }

    public function acceptConsent(): void
    {
        $this->getElement('consent')->check();
    }

    public function save(): void
    {
        $this->getElement('save')->press();
    }

    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            'consent' => '[data-test-subscription-consent-checkbox]',
            'item' => '[data-test-item="%product%"]',
            'new_renewal_total' => '[data-test-new-renewal-total]',
            'save' => '[data-test-confirm-items-change]',
        ]);
    }

    private function fieldOf(string $productName, string $field): NodeElement
    {
        $element = $this->getElement('item', ['%product%' => $productName])->find('css', \sprintf('[data-test-%s]', $field));
        if (null === $element) {
            throw new \InvalidArgumentException(\sprintf('"%s" has no %s to change.', $productName, $field));
        }

        return $element;
    }
}
