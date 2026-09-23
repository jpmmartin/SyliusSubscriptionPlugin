<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin;

use JpmMartin\SyliusSubscriptionPlugin\DependencyInjection\Compiler\OrderItemMustCarrySubscriptionPlanPass;
use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Sylius\Bundle\ResourceBundle\AbstractResourceBundle;
use Sylius\Bundle\ResourceBundle\SyliusResourceBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class JpmMartinSyliusSubscriptionPlugin extends AbstractResourceBundle
{
    use SyliusPluginTrait;

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    /** @return list<string> */
    public function getSupportedDrivers(): array
    {
        return [SyliusResourceBundle::DRIVER_DOCTRINE_ORM];
    }

    protected function getModelNamespace(): string
    {
        return 'JpmMartin\SyliusSubscriptionPlugin\Entity';
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new OrderItemMustCarrySubscriptionPlanPass());
    }

    /**
     * The bundle path is the repository root, and this plugin keeps its configuration under
     * config/ rather than Resources/config/, so the mapping files sit next to the rest of it.
     */
    protected function getConfigFilesPath(): string
    {
        return $this->getPath() . '/config/doctrine/model';
    }
}
