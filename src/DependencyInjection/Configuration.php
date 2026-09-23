<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\DependencyInjection;

use JpmMartin\SyliusSubscriptionPlugin\Entity\CartFrequency;
use JpmMartin\SyliusSubscriptionPlugin\Entity\CartFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\RepeatableVariant;
use JpmMartin\SyliusSubscriptionPlugin\Entity\RepeatableVariantInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\Subscription;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionChargeAttempt;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionChargeAttemptInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionConsent;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionConsentInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycle;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleItem;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequency;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItem;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlan;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionCycleRepository;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionFrequencyRepository;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepository;
use Sylius\Bundle\ResourceBundle\Controller\ResourceController;
use Sylius\Bundle\ResourceBundle\SyliusResourceBundle;
use Sylius\Resource\Factory\Factory;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        // The root name matches the extension alias Symfony derives from the extension's class name.
        $treeBuilder = new TreeBuilder('jpm_martin_sylius_subscription');

        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('driver')->defaultValue(SyliusResourceBundle::DRIVER_DOCTRINE_ORM)->end()
                ->arrayNode('payment_methods')
                    ->info('Codes of the payment methods the default renewal charger may charge without the customer present. Empty means nobody can subscribe until the store chooses them.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('retry_delays')
                    ->info('Days after the first charge attempt at which a declined or unattempted renewal charge is retried, in increasing order.')
                    ->integerPrototype()->min(1)->end()
                    ->defaultValue([1, 3, 7])
                    ->validate()
                        ->ifTrue(static function (array $delays): bool {
                            for ($i = 1, $count = \count($delays); $i < $count; ++$i) {
                                if ($delays[$i] <= $delays[$i - 1]) {
                                    return true;
                                }
                            }

                            return false;
                        })
                        ->thenInvalid('The retry delays must be strictly increasing, got %s.')
                    ->end()
                ->end()
                ->arrayNode('final_decline_codes')
                    ->info('Gateway codes of declines the default retry policy never retries: the cycle fails at once. Each gateway has its own codes, so none is set by default.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue([])
                ->end()
                ->scalarNode('suspend_after_failed_cycles')
                    ->info('Cycles failed in a row after which a subscription is suspended; null never suspends it. A failed cycle never cancels a subscription: only its customer or an administrator do.')
                    ->defaultValue(3)
                    ->validate()
                        ->ifTrue(static fn (mixed $value): bool => null !== $value && (!\is_int($value) || $value < 1))
                        ->thenInvalid('The number of failed cycles in a row before suspending must be a positive integer, or null to never suspend; got %s.')
                    ->end()
                ->end()
                // Removed: kept only to point a store that still sets it to its replacement.
                ->variableNode('on_failure')
                    ->validate()
                        ->ifTrue(static fn (): bool => true)
                        ->thenInvalid('"on_failure" has been removed, because a failed cycle no longer suspends or cancels a subscription by itself. Use "suspend_after_failed_cycles" to choose after how many failed cycles in a row a subscription is suspended (got %s).')
                    ->end()
                ->end()
                ->scalarNode('consent_version')
                    ->info('Identifier of the recurring-charge consent text currently shown. Change it whenever the text changes.')
                    ->cannotBeEmpty()
                    ->defaultValue('1')
                ->end()
            ->end()
        ;

        $this->addResourcesSection($rootNode);

        return $treeBuilder;
    }

    private function addResourcesSection(ArrayNodeDefinition $node): void
    {
        $resources = $node
            ->children()
                ->arrayNode('resources')
                    ->addDefaultsIfNotSet()
                    ->children()
        ;

        $this->addResource($resources, 'subscription_plan', SubscriptionPlan::class, SubscriptionPlanInterface::class);
        $this->addResource($resources, 'subscription_frequency', SubscriptionFrequency::class, SubscriptionFrequencyInterface::class, SubscriptionFrequencyRepository::class);
        $this->addResource($resources, 'repeatable_variant', RepeatableVariant::class, RepeatableVariantInterface::class);
        $this->addResource($resources, 'cart_frequency', CartFrequency::class, CartFrequencyInterface::class);
        $this->addResource($resources, 'subscription', Subscription::class, SubscriptionInterface::class, SubscriptionRepository::class);
        $this->addResource($resources, 'subscription_item', SubscriptionItem::class, SubscriptionItemInterface::class);
        $this->addResource($resources, 'subscription_cycle', SubscriptionCycle::class, SubscriptionCycleInterface::class, SubscriptionCycleRepository::class);
        $this->addResource($resources, 'subscription_cycle_item', SubscriptionCycleItem::class, SubscriptionCycleItemInterface::class);
        $this->addResource($resources, 'subscription_charge_attempt', SubscriptionChargeAttempt::class, SubscriptionChargeAttemptInterface::class);
        $this->addResource($resources, 'subscription_consent', SubscriptionConsent::class, SubscriptionConsentInterface::class);
    }

    /** @param class-string|null $repository null for Sylius's own repository */
    private function addResource(NodeBuilder $resources, string $name, string $model, string $interface, ?string $repository = null): void
    {
        $resources
            ->arrayNode($name)
                ->addDefaultsIfNotSet()
                ->children()
                    ->arrayNode('classes')
                        ->addDefaultsIfNotSet()
                        ->children()
                            ->scalarNode('model')->defaultValue($model)->cannotBeEmpty()->end()
                            ->scalarNode('interface')->defaultValue($interface)->cannotBeEmpty()->end()
                            ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                            ->scalarNode('repository')->defaultValue($repository)->end()
                            ->scalarNode('factory')->defaultValue(Factory::class)->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }
}
