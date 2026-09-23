<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Admin\SubscriptionPlan;

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

    /** @return array<string, string> */
    protected function getDefinedFormElements(): array
    {
        return [
            'code' => '#jpm_martin_sylius_subscription_subscription_plan_code',
            'name' => '#jpm_martin_sylius_subscription_subscription_plan_name',
            'interval_count' => '#jpm_martin_sylius_subscription_subscription_plan_intervalCount',
            'interval_unit' => '#jpm_martin_sylius_subscription_subscription_plan_intervalUnit',
            'discount_percentage' => '#jpm_martin_sylius_subscription_subscription_plan_discountPercentage',
            'max_cycles' => '#jpm_martin_sylius_subscription_subscription_plan_maxCycles',
            'enabled' => '#jpm_martin_sylius_subscription_subscription_plan_enabled',
        ];
    }
}
