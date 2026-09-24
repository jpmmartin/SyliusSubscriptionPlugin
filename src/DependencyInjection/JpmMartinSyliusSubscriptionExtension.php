<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\DependencyInjection;

use JpmMartin\SyliusSubscriptionPlugin\Gate\CycleGateInterface;
use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Sylius\Bundle\ResourceBundle\DependencyInjection\Extension\AbstractResourceExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

final class JpmMartinSyliusSubscriptionExtension extends AbstractResourceExtension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{driver: string, resources: array<string, mixed>, payment_methods: list<string>, retry_delays: list<int>, final_decline_codes: list<string>, suspend_after_failed_cycles: int|null, missed_cycles: string, consent_version: string} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $this->registerResources('jpm_martin_sylius_subscription', $config['driver'], $config['resources'], $container);

        $container->setParameter('jpm_martin_sylius_subscription.payment_methods', $config['payment_methods']);
        $container->setParameter('jpm_martin_sylius_subscription.retry_delays', $config['retry_delays']);
        $container->setParameter('jpm_martin_sylius_subscription.final_decline_codes', $config['final_decline_codes']);
        $container->setParameter('jpm_martin_sylius_subscription.suspend_after_failed_cycles', $config['suspend_after_failed_cycles']);
        $container->setParameter('jpm_martin_sylius_subscription.missed_cycles', $config['missed_cycles']);
        $container->setParameter('jpm_martin_sylius_subscription.consent_version', $config['consent_version']);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));

        $loader->load('services.xml');

        $container->registerForAutoconfiguration(CycleGateInterface::class)->addTag('jpm_martin_sylius_subscription.cycle_gate');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $this->prependDoctrineMigrations($container);

        // Other bundles' configuration is resolved before this bundle is loaded, so the model classes
        // its grids name are set now, from the store's own configuration of the plugin.
        /** @var array{resources: array<string, array{classes: array{model: string}}>} $config */
        $config = $this->processConfiguration(new Configuration(), $container->getExtensionConfig($this->getAlias()));
        foreach ($config['resources'] as $name => $resource) {
            $container->setParameter(\sprintf('jpm_martin_sylius_subscription.model.%s.class', $name), $resource['classes']['model']);
        }

        // The plugin's shop API operations, registered the way Sylius registers its own resources.
        $container->prependExtensionConfig('api_platform', [
            'mapping' => ['paths' => [\dirname(__DIR__, 2) . '/config/api_platform']],
        ]);
    }

    protected function getMigrationsNamespace(): string
    {
        // Owned by this plugin, never the skeleton's generic "DoctrineMigrations": that namespace is
        // the one a consuming application uses for its own migrations, and the migrations table
        // records the fully qualified class name, so the namespace is frozen once a migration ships.
        return 'JpmMartin\\SyliusSubscriptionPlugin\\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@JpmMartinSyliusSubscriptionPlugin/src/Migrations';
    }

    /** @return list<string> */
    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return [
            'Sylius\Bundle\CoreBundle\Migrations',
        ];
    }
}
