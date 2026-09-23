<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Admin\SubscriptionPlan;

use Sylius\Behat\Page\Admin\Crud\CreatePage as BaseCreatePage;

final class CreatePage extends BaseCreatePage
{
    use FormElementsTrait;

    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), $this->getDefinedFormElements());
    }
}
