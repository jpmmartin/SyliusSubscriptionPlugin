<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Admin\SubscriptionPlan;

use Sylius\Behat\Page\Admin\Crud\UpdatePage as BaseUpdatePage;

final class UpdatePage extends BaseUpdatePage
{
    use FormElementsTrait;

    public function disable(): void
    {
        $this->getElement('enabled')->uncheck();
    }

    /** Presses the modal's confirmation, which the browser-kit driver reaches without opening it. */
    public function delete(): void
    {
        $this->getDocument()->find('css', '[data-test-confirm-button]')->press();
    }

    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), $this->getDefinedFormElements());
    }
}
