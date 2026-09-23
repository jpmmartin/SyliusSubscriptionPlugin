<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Shop\Cart;

use Behat\Mink\Element\NodeElement;
use FriendsOfBehat\PageObjectExtension\Page\SymfonyPage;

/** "Repeat this cart" on Sylius's cart page, and what each line renews on. */
final class RepeatCartPage extends SymfonyPage
{
    public function getRouteName(): string
    {
        return 'sylius_shop_cart_summary';
    }

    public function isRepeatingOffered(): bool
    {
        return $this->hasElement('repeat_cart');
    }

    /** @return list<string> */
    public function getOptions(): array
    {
        return array_map(fn (NodeElement $option): string => $this->labelOf($option), $this->getOptionElements());
    }

    public function repeatWith(string $label): void
    {
        foreach ($this->getOptionElements() as $option) {
            if ($this->labelOf($option) === $label) {
                $this->choose((string) $option->getAttribute('value'));

                return;
            }
        }

        throw new \RuntimeException(\sprintf('The cart cannot be repeated "%s".', $label));
    }

    public function stopRepeating(): void
    {
        $this->choose('');
    }

    public function getRepetitionOf(string $productName): ?string
    {
        return $this->getItemRow($productName)->find('css', '[data-test-cart-item-subscription-frequency]')?->getText();
    }

    public function isBoughtOnce(string $productName): bool
    {
        return null !== $this->getItemRow($productName)->find('css', '[data-test-cart-item-bought-once]');
    }

    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            'item_row' => '[data-test-cart-item-product-row="%name%"]',
            'repeat_cart' => '[data-test-repeat-cart]',
            'repeat_cart_button' => '[data-test-repeat-cart-button]',
        ]);
    }

    private function choose(string $code): void
    {
        $this->getElement('repeat_cart')->selectFieldOption('subscription_frequency', $code);
        $this->getElement('repeat_cart_button')->press();
    }

    /** @return list<NodeElement> */
    private function getOptionElements(): array
    {
        return array_values($this->getElement('repeat_cart')->findAll('css', 'input[name="subscription_frequency"]'));
    }

    private function labelOf(NodeElement $option): string
    {
        $label = $this->getElement('repeat_cart')->find('css', \sprintf('label[for="%s"]', (string) $option->getAttribute('id')));
        if (null === $label) {
            throw new \RuntimeException('A "Repeat this cart" option has no label.');
        }

        return $label->getText();
    }

    private function getItemRow(string $productName): NodeElement
    {
        return $this->getElement('item_row', ['%name%' => $productName]);
    }
}
