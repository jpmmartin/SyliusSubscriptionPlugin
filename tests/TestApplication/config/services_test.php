<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Gate\ScriptedCycleGate;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedPaymentRequestCommandProvider;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedPaymentRequestHandler;

return function (ContainerConfigurator $container) {
    if (str_starts_with($container->env(), 'test')) {
        $container->import('../../../vendor/sylius/sylius/src/Sylius/Behat/Resources/config/services.xml');
        $container->import('@JpmMartinSyliusSubscriptionPlugin/tests/Behat/Resources/services.xml');

        $services = $container->services();

        $services
            ->set('jpm_martin_sylius_subscription.test.cycle_gate', ScriptedCycleGate::class)
                ->public()
                ->tag('jpm_martin_sylius_subscription.cycle_gate')
        ;

        // The "scripted" gateway factory: a card gateway whose answers the tests decide.
        $services
            ->set('jpm_martin_sylius_subscription.test.scripted_gateway', ScriptedGateway::class)
                ->public()
        ;
        $services
            ->set('jpm_martin_sylius_subscription.test.command_provider.scripted', ScriptedPaymentRequestCommandProvider::class)
                ->tag('sylius.payment_request.command_provider', ['gateway_factory' => 'scripted'])
        ;
        $services
            ->set('jpm_martin_sylius_subscription.test.command_handler.scripted', ScriptedPaymentRequestHandler::class)
                ->args([
                    service('sylius.provider.payment_request'),
                    service('sylius_abstraction.state_machine'),
                    service('jpm_martin_sylius_subscription.test.scripted_gateway'),
                ])
                ->tag('messenger.message_handler', ['bus' => 'sylius.payment_request.command_bus'])
        ;
    }
};
