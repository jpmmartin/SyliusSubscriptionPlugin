<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

/**
 * The subscriptions, with the orders and payments in the admin's sales menu; the store's
 * frequencies, with the rest of its configuration.
 */
final class AdminMainMenuListener
{
    public function __invoke(MenuBuilderEvent $event): void
    {
        $event->getMenu()->getChild('configuration')
            ?->addChild('subscription_frequencies', ['route' => 'jpm_martin_sylius_subscription_admin_subscription_frequency_index', 'extras' => ['routes' => [
                ['route' => 'jpm_martin_sylius_subscription_admin_subscription_frequency_create'],
                ['route' => 'jpm_martin_sylius_subscription_admin_subscription_frequency_update'],
            ]]])
            ->setLabel('jpm_martin_sylius_subscription.ui.subscription_frequencies')
            ->setLabelAttribute('icon', 'tabler:calendar-repeat')
        ;

        $sales = $event->getMenu()->getChild('sales');
        if (null === $sales) {
            return;
        }

        $sales
            ->addChild('subscriptions', ['route' => 'jpm_martin_sylius_subscription_admin_subscription_index', 'extras' => ['routes' => [
                ['route' => 'jpm_martin_sylius_subscription_admin_subscription_show'],
                ['route' => 'jpm_martin_sylius_subscription_admin_subscription_change_frequency'],
            ]]])
            ->setLabel('jpm_martin_sylius_subscription.ui.subscriptions')
            ->setLabelAttribute('icon', 'tabler:repeat')
        ;
    }
}
