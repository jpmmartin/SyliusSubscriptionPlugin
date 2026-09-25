<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Admin\Subscription;

use FriendsOfBehat\PageObjectExtension\Page\SymfonyPage;

final class ShowPage extends SymfonyPage
{
    public function getRouteName(): string
    {
        return 'jpm_martin_sylius_subscription_admin_subscription_show';
    }

    /** One of the details: state, customer, frequency, price, next-renewal or consecutive-failed-cycles. */
    public function getDetail(string $detail): string
    {
        return trim($this->getElement('detail', ['%detail%' => $detail])->getText());
    }

    public function getConsent(string $field): string
    {
        return trim($this->getElement('consent_field', ['%field%' => $field])->getText());
    }

    public function getRenewalState(int $number): string
    {
        return trim((string) $this->getElement('cycle', ['%number%' => (string) $number])->find('css', 'td:nth-child(3) .badge')?->getText());
    }

    /** @return list<array{attempted_at: string, outcome: string, reason: string, code: string}> */
    public function getAttemptsOfRenewal(int $number): array
    {
        $attempts = [];
        foreach ($this->getElement('cycle', ['%number%' => (string) $number])->findAll('css', '[data-test-attempt]') as $attempt) {
            $attempts[] = [
                'attempted_at' => trim((string) $attempt->find('css', '[data-test-attempted-at]')?->getText()),
                'outcome' => trim((string) $attempt->find('css', '[data-test-outcome]')?->getText()),
                'reason' => trim((string) $attempt->find('css', '[data-test-reason]')?->getText()),
                'code' => trim((string) $attempt->find('css', '[data-test-code]')?->getText()),
            ];
        }

        return $attempts;
    }

    /** @return list<array{attempted_at: string, type: string}> */
    public function getAttemptTypesOfRenewal(int $number): array
    {
        $attempts = [];
        foreach ($this->getElement('cycle', ['%number%' => (string) $number])->findAll('css', '[data-test-attempt]') as $attempt) {
            $attempts[] = [
                'attempted_at' => trim((string) $attempt->find('css', '[data-test-attempted-at]')?->getText()),
                'type' => trim((string) $attempt->find('css', '[data-test-attempt-type]')?->getText()),
            ];
        }

        return $attempts;
    }

    public function canApply(string $transition): bool
    {
        return $this->hasElement('transition', ['%transition%' => $transition]);
    }

    public function apply(string $transition): void
    {
        $this->getElement('transition', ['%transition%' => $transition])->press();
    }

    public function skipRenewal(): void
    {
        $this->getElement('skip_renewal')->press();
    }

    public function changeAddress(): void
    {
        $this->getElement('change_address')->click();
    }

    public function changeFrequency(): void
    {
        $this->getElement('change_frequency')->click();
    }

    /** One column of the item of that product: plan, quantity, unit-price or paid-cycles. */
    public function getItem(string $productName, string $column): string
    {
        $cell = $this->getElement('item', ['%product%' => $productName])->find('css', \sprintf('[data-test-item-%s]', $column));

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

    public function canRetryRenewal(int $number): bool
    {
        return null !== $this->getElement('cycle', ['%number%' => (string) $number])->find('css', '[data-test-retry]');
    }

    public function retryRenewal(int $number): void
    {
        $button = $this->getElement('cycle', ['%number%' => (string) $number])->find('css', '[data-test-retry]');
        if (null === $button) {
            throw new \InvalidArgumentException(\sprintf('Renewal #%d cannot be retried.', $number));
        }

        $button->press();
    }

    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            'change_address' => '[data-test-change-address]',
            'change_frequency' => '[data-test-change-frequency]',
            'consent_field' => '[data-test-consent-%field%]',
            'cycle' => '[data-test-cycle="%number%"]',
            'detail' => '[data-test-subscription-%detail%]',
            'item' => '[data-test-item="%product%"]',
            'skip_renewal' => 'button[data-test-skip-renewal]',
            'transition' => 'button[data-test-%transition%]',
        ]);
    }
}
