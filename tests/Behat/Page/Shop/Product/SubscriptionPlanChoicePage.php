<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Shop\Product;

use Behat\Mink\Element\NodeElement;
use FriendsOfBehat\PageObjectExtension\Page\SymfonyPage;

/** The choice between buying once and subscribing, on Sylius's product page. */
final class SubscriptionPlanChoicePage extends SymfonyPage
{
    public function getRouteName(): string
    {
        return 'sylius_shop_product_show';
    }

    public function hasPlanChoice(): bool
    {
        return $this->hasElement('plan_choice');
    }

    /** @return list<string> the codes of the plans offered, without the one-time purchase */
    public function getOfferedPlanCodes(): array
    {
        $codes = array_map(
            static fn (NodeElement $radio): string => (string) $radio->getAttribute('value'),
            $this->getPlanRadios(),
        );

        return array_values(array_filter($codes, static fn (string $code): bool => '' !== $code));
    }

    /** @return list<string> the labels of the choices, buying once first, as the customer reads them */
    public function getChoiceLabels(): array
    {
        return array_map(
            fn (NodeElement $radio): string => trim((string) $this->getElement('plan_choice')->find('css', \sprintf('label[for="%s"]', $radio->getAttribute('id')))?->getText()),
            $this->getPlanRadios(),
        );
    }

    public function isOneTimePurchaseOffered(): bool
    {
        foreach ($this->getPlanRadios() as $radio) {
            if ('' === $radio->getAttribute('value')) {
                return true;
            }
        }

        return false;
    }

    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            'plan_choice' => '[data-test-subscription-plans]',
        ]);
    }

    /** @return list<NodeElement> */
    private function getPlanRadios(): array
    {
        if (!$this->hasPlanChoice()) {
            return [];
        }

        return array_values($this->getElement('plan_choice')->findAll('css', 'input[type="radio"][name$="[subscriptionPlan]"]'));
    }
}
