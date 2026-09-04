<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    /**
     * @psalm-suppress UnusedVariable
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('jpmmartin_sylius_subscription');
        $rootNode = $treeBuilder->getRootNode();

        return $treeBuilder;
    }
}
