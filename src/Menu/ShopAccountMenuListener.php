<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

/** The customer's subscriptions, next to their order history. */
final class ShopAccountMenuListener
{
    public function __invoke(MenuBuilderEvent $event): void
    {
        $event->getMenu()
            ->addChild('subscriptions', ['route' => 'jpm_martin_sylius_subscription_shop_account_subscription_index'])
            ->setLabel('jpm_martin_sylius_subscription.ui.my_subscriptions')
            ->setLabelAttribute('icon', 'tabler:repeat')
        ;
    }
}
