<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Shop\Account\Subscription;

use FriendsOfBehat\PageObjectExtension\Page\SymfonyPage;

final class ShowPage extends SymfonyPage
{
    public function getRouteName(): string
    {
        return 'jpm_martin_sylius_subscription_shop_account_subscription_show';
    }

    /** One of the details: state, frequency, price or next-renewal. */
    public function getDetail(string $detail): string
    {
        return trim($this->getElement('detail', ['%detail%' => $detail])->getText());
    }

    public function countRenewals(): int
    {
        return \count($this->getDocument()->findAll('css', '[data-test-cycle]'));
    }

    /** The date or the state of the renewal with that number. */
    public function getRenewal(int $number, string $column): string
    {
        $cell = $this->getElement('cycle', ['%number%' => (string) $number])->find('css', \sprintf('[data-test-%s]', $column));

        return null === $cell ? '' : trim($cell->getText());
    }

    public function canBeCancelled(): bool
    {
        return $this->hasElement('cancel');
    }

    public function cancel(): void
    {
        $this->getElement('cancel')->press();
    }

    /** Pause or resume, whichever the button says. */
    public function canApply(string $transition): bool
    {
        return $this->hasElement($transition);
    }

    public function apply(string $transition): void
    {
        $this->getElement($transition)->press();
    }

    /** The text of the button that skips the next renewal, which says its date; null when it is not offered. */
    public function getSkipRenewalButton(): ?string
    {
        return $this->hasElement('skip_renewal') ? trim($this->getElement('skip_renewal')->getText()) : null;
    }

    public function skipRenewal(): void
    {
        $this->getElement('skip_renewal')->press();
    }

    public function canChangeFrequency(): bool
    {
        return $this->hasElement('change_frequency');
    }

    public function changeFrequency(): void
    {
        $this->getElement('change_frequency')->click();
    }

    /** One column of the item of that product: quantity, unit-price, total or variant. */
    public function getItem(string $productName, string $column): string
    {
        $cell = $this->getElement('item', ['%product%' => $productName])->find('css', 'variant' === $column ? '[data-test-variant]' : \sprintf('[data-test-item-%s]', $column));

        return null === $cell ? '' : trim($cell->getText());
    }

    /** @return array<string, string> the reason each skipped product was skipped, by product */
    public function getSkippedItemsOfRenewal(int $number): array
    {
        $skipped = [];
        foreach ($this->getElement('cycle', ['%number%' => (string) $number])->findAll('css', '[data-test-skipped-item]') as $item) {
            $skipped[(string) $item->getAttribute('data-test-skipped-item')] = trim((string) $item->find('css', '[data-test-skipped-reason]')?->getText());
        }

        return $skipped;
    }

    public function getStatusCode(): int
    {
        return $this->getSession()->getStatusCode();
    }

    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            'cancel' => '[data-test-cancel]',
            'change_frequency' => '[data-test-change-frequency]',
            'cycle' => '[data-test-cycle="%number%"]',
            'detail' => '[data-test-subscription-%detail%]',
            'item' => '[data-test-item="%product%"]',
            'pause' => 'button[data-test-pause]',
            'resume' => 'button[data-test-resume]',
            'skip_renewal' => 'button[data-test-skip-renewal]',
        ]);
    }
}
