<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Admin\ProductVariant;

use Behat\Mink\Element\NodeElement;
use FriendsOfBehat\PageObjectExtension\Page\SymfonyPage;

/** The "Subscription" tab of Sylius's variant edit page: the "can be repeated" switch and the plans. */
final class SubscriptionPlansPage extends SymfonyPage
{
    public function getRouteName(): string
    {
        return 'sylius_admin_product_variant_update';
    }

    public function markRepeatable(): void
    {
        $this->getElement('repeatable')->check();
    }

    public function unmarkRepeatable(): void
    {
        $this->getElement('repeatable')->uncheck();
    }

    public function isMarkedRepeatable(): bool
    {
        return $this->getElement('repeatable')->isChecked();
    }

    public function saveChanges(): void
    {
        $this->getElement('save_changes')->click();
    }

    public function addPlan(): void
    {
        $this->getElement('add_plan')->click();
    }

    public function editPlan(string $code): void
    {
        $this->getElement('edit_plan', ['%code%' => $code])->click();
    }

    public function hasPlan(string $code): bool
    {
        return $this->hasElement('plan', ['%code%' => $code]);
    }

    public function hasNoPlans(): bool
    {
        return $this->hasElement('no_plans');
    }

    public function getPlanInterval(string $code): string
    {
        return $this->getPlanCell($code, 'subscription-plan-interval')->getText();
    }

    public function getPlanDiscount(string $code): string
    {
        return $this->getPlanCell($code, 'subscription-plan-discount')->getText();
    }

    public function isPlanEnabled(string $code): bool
    {
        return 'Enabled' === $this->getPlanCell($code, 'subscription-plan-enabled')->getText();
    }

    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            'add_plan' => '[data-test-add-subscription-plan]',
            'edit_plan' => '[data-test-edit-subscription-plan="%code%"]',
            'no_plans' => '[data-test-no-subscription-plans]',
            'plan' => '[data-test-subscription-plan="%code%"]',
            'repeatable' => '[data-test-subscription-repeatable]',
            'save_changes' => '[data-test-update-changes-button]',
        ]);
    }

    private function getPlanCell(string $code, string $cell): NodeElement
    {
        $cellElement = $this->getElement('plan', ['%code%' => $code])->find('css', \sprintf('[data-test-%s]', $cell));
        if (null === $cellElement) {
            throw new \RuntimeException(\sprintf('The "%s" plan has no "%s" cell.', $code, $cell));
        }

        return $cellElement;
    }
}
