<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Admin\ProductVariant;

use Behat\Mink\Element\NodeElement;
use FriendsOfBehat\PageObjectExtension\Page\SymfonyPage;
use Sylius\Behat\Service\DriverHelper;

/**
 * The "Subscription" tab of Sylius's variant edit page: the "can be repeated" switch and the plans.
 * The variant's form is a live component: in a browser, a field is reached through its tab, and each
 * change waits for the form to be rendered again.
 */
final class SubscriptionPlansPage extends SymfonyPage
{
    public function getRouteName(): string
    {
        return 'sylius_admin_product_variant_update';
    }

    public function markRepeatable(): void
    {
        $this->showTab('subscription-plans');
        $this->getElement('repeatable')->check();
        DriverHelper::waitForLiveComponentUpdate($this->getSession());
    }

    public function unmarkRepeatable(): void
    {
        $this->showTab('subscription-plans');
        $this->getElement('repeatable')->uncheck();
        DriverHelper::waitForLiveComponentUpdate($this->getSession());
    }

    public function isMarkedRepeatable(): bool
    {
        return $this->getElement('repeatable')->isChecked();
    }

    /** Sylius's own "Shipping required" switch, on the variant's general tab. */
    public function requireShipping(bool $required): void
    {
        $this->showTab('details');
        $field = $this->getElement('shipping_required');
        $required ? $field->check() : $field->uncheck();
        DriverHelper::waitForLiveComponentUpdate($this->getSession());
    }

    public function isShippingRequired(): bool
    {
        return $this->getElement('shipping_required')->isChecked();
    }

    public function saveChanges(): void
    {
        $this->getElement('save_changes')->click();
        DriverHelper::waitForPageToLoad($this->getSession());
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
            'shipping_required' => '#sylius_admin_product_variant_shippingRequired',
            'side_navigation_tab' => '[data-test-side-navigation-tab="%tab%"]',
        ]);
    }

    /** Without a browser every tab is in the page already; in one, only the open tab can be used. */
    private function showTab(string $tab): void
    {
        if (DriverHelper::isJavascript($this->getDriver())) {
            $this->getElement('side_navigation_tab', ['%tab%' => $tab])->click();
        }
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
