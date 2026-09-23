<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Admin\SubscriptionFrequency;

use Sylius\Behat\Page\Admin\Crud\UpdatePage as BaseUpdatePage;

final class UpdatePage extends BaseUpdatePage
{
    use FormElementsTrait;

    public function disable(): void
    {
        $this->getElement('enabled')->uncheck();
    }

    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), $this->getDefinedFormElements());
    }
}
