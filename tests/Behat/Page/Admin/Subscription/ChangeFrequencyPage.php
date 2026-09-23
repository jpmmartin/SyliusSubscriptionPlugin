<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Admin\Subscription;

use FriendsOfBehat\PageObjectExtension\Page\SymfonyPage;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Shared\FrequencyChoice;

final class ChangeFrequencyPage extends SymfonyPage
{
    public function getRouteName(): string
    {
        return 'jpm_martin_sylius_subscription_admin_subscription_change_frequency';
    }

    public function chooseFrequency(string $label): void
    {
        FrequencyChoice::choose($this->getDocument(), $label);
    }

    public function confirm(): void
    {
        $this->getElement('confirm')->press();
    }

    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            'confirm' => '[data-test-confirm-frequency-change]',
        ]);
    }
}
