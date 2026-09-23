<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Admin\Subscription;

use Sylius\Behat\Page\Admin\Crud\IndexPage as BaseIndexPage;

final class IndexPage extends BaseIndexPage
{
    public function filterByState(string $stateLabel): void
    {
        $this->getDocument()->selectFieldOption('criteria_state', $stateLabel);
    }

    public function filterByCustomer(string $phrase): void
    {
        $this->getDocument()->fillField('criteria_customer_value', $phrase);
    }

    public function filterByVariant(string $phrase): void
    {
        $this->getDocument()->fillField('criteria_variant_value', $phrase);
    }

    public function filterByNextRenewal(string $from, string $to): void
    {
        $this->getDocument()->fillField('criteria_nextRenewal_from_date', $from);
        $this->getDocument()->fillField('criteria_nextRenewal_to_date', $to);
    }

    public function showSubscriptionOf(string $email): void
    {
        $this->getActionsForResource(['customer' => $email])->find('css', '[data-test-show-action]')?->click();
    }
}
