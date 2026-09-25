<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Shop\Order;

use FriendsOfBehat\PageObjectExtension\Page\SymfonyPage;

/** Sylius's own order payment page, "/order/{tokenValue}", where a renewal whose charge was declined is paid. */
final class PaymentPage extends SymfonyPage
{
    public function getRouteName(): string
    {
        return 'sylius_shop_order_show';
    }

    public function pay(): void
    {
        $this->getElement('pay')->press();
    }

    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            'pay' => '[data-test-pay-link]',
        ]);
    }
}
