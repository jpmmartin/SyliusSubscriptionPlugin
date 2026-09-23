<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Admin\SubscriptionFrequency;

trait FormElementsTrait
{
    public function specifyCode(string $code): void
    {
        $this->getElement('code')->setValue($code);
    }

    public function nameIt(string $name): void
    {
        $this->getElement('name')->setValue($name);
    }

    public function setInterval(int $count, string $unit): void
    {
        $this->getElement('interval_count')->setValue((string) $count);
        $this->getElement('interval_unit')->selectOption($unit);
    }

    public function setDiscount(int $percentage): void
    {
        $this->getElement('discount_percentage')->setValue((string) $percentage);
    }

    public function makeAvailableIn(string $channelName): void
    {
        $this->getDocument()->checkField($channelName);
    }

    public function isEnabled(): bool
    {
        return $this->getElement('enabled')->isChecked();
    }

    /** @return array<string, string> */
    protected function getDefinedFormElements(): array
    {
        return [
            'code' => '#jpm_martin_sylius_subscription_subscription_frequency_code',
            'name' => '#jpm_martin_sylius_subscription_subscription_frequency_name',
            'interval_count' => '#jpm_martin_sylius_subscription_subscription_frequency_intervalCount',
            'interval_unit' => '#jpm_martin_sylius_subscription_subscription_frequency_intervalUnit',
            'discount_percentage' => '#jpm_martin_sylius_subscription_subscription_frequency_discountPercentage',
            'enabled' => '#jpm_martin_sylius_subscription_subscription_frequency_enabled',
        ];
    }
}
