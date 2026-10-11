<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Shop\Cart;

use Behat\Mink\Element\NodeElement;
use FriendsOfBehat\PageObjectExtension\Page\SymfonyPage;
use Sylius\Behat\Service\DriverHelper;

/** "Repeat this cart" on Sylius's cart page, and what each line renews on: its plan or its frequency. */
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

    /**
     * The plain badge's own code attribute when the line has only this one option, as before; the dropdown's
     * current choice otherwise (choose-subscription-per-cart-line), read back off its "Subscribe: <name>" text.
     * That only recovers the plan's code when its name is the code, true of every plan these scenarios create
     * (SubscriptionPlanContext sets both to the same value); a plan with a different display name would need a
     * lookup this page object has no way to make.
     */
    public function getPlanOf(string $productName): ?string
    {
        $badge = $this->getItemRow($productName)->find('css', '[data-test-cart-item-subscription-plan]');
        if (null !== $badge) {
            return $badge->getAttribute('data-test-cart-item-subscription-plan');
        }

        if (!$this->hasSubscriptionOptions($productName)) {
            return null;
        }

        $current = $this->getCurrentSubscriptionChoice($productName);

        return str_starts_with($current, 'Subscribe: ') ? substr($current, \strlen('Subscribe: ')) : null;
    }

    /**
     * The plain badge when the line has only this one option, as before; its dropdown's current choice,
     * reworded the same way, once it has more than one (choose-subscription-per-cart-line) and so has no badge
     * of its own any more.
     */
    public function getRepetitionOf(string $productName): ?string
    {
        $badge = $this->getItemRow($productName)->find('css', '[data-test-cart-item-subscription-frequency]');
        if (null !== $badge) {
            return $badge->getText();
        }

        if (!$this->hasSubscriptionOptions($productName)) {
            return null;
        }

        $current = $this->getCurrentSubscriptionChoice($productName);
        if ('One-time purchase' === $current || str_starts_with($current, 'Subscribe: ')) {
            return null;
        }

        return 'Repeated: ' . $current;
    }

    public function getIntroductoryPriceOf(string $productName): ?string
    {
        return $this->getItemRow($productName)->find('css', '[data-test-cart-item-introductory-price]')?->getText();
    }

    public function getPrepaidOf(string $productName): ?string
    {
        return $this->getItemRow($productName)->find('css', '[data-test-cart-item-prepaid]')?->getText();
    }

    public function getCommitmentOf(string $productName): ?string
    {
        return $this->getItemRow($productName)->find('css', '[data-test-cart-item-commitment]')?->getText();
    }

    public function isBoughtOnce(string $productName): bool
    {
        return null !== $this->getItemRow($productName)->find('css', '[data-test-cart-item-bought-once]');
    }

    /** choose-subscription-per-cart-line: a single line's own dropdown, present only with more than one option. */
    public function hasSubscriptionOptions(string $productName): bool
    {
        return null !== $this->subscriptionOptionsOf($productName);
    }

    /** What the dropdown itself currently shows: the same text as its highlighted option. */
    public function getCurrentSubscriptionChoice(string $productName): string
    {
        $toggle = $this->subscriptionOptionsOf($productName)?->find('css', '[data-test-cart-item-subscription-toggle]');
        if (null === $toggle) {
            throw new \RuntimeException(\sprintf('The "%s" line has no subscription dropdown.', $productName));
        }

        return trim($toggle->getText());
    }

    public function chooseSubscriptionOption(string $productName, string $label): void
    {
        foreach ($this->subscriptionOptionElementsOf($productName) as $option) {
            if (trim($option->getText()) === $label) {
                $option->press();
                DriverHelper::waitForPageToLoad($this->getSession());

                return;
            }
        }

        throw new \RuntimeException(\sprintf('The "%s" line has no "%s" subscription option.', $productName, $label));
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
        DriverHelper::waitForPageToLoad($this->getSession());
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

    private function subscriptionOptionsOf(string $productName): ?NodeElement
    {
        return $this->getItemRow($productName)->find('css', '[data-test-cart-item-subscription-options]');
    }

    /** @return list<NodeElement> */
    private function subscriptionOptionElementsOf(string $productName): array
    {
        $options = $this->subscriptionOptionsOf($productName);
        if (null === $options) {
            throw new \RuntimeException(\sprintf('The "%s" line has no subscription options to choose from.', $productName));
        }

        return array_values($options->findAll('css', '[data-test-cart-item-subscription-option]'));
    }
}
