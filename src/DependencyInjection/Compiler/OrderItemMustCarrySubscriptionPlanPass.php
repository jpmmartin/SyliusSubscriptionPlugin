<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\DependencyInjection\Compiler;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;

/**
 * Stops the container from compiling when the store skipped the plugin's one installation step.
 *
 * The chosen plan lives on the order line, so a store whose OrderItem cannot hold it would accept
 * subscription lines and silently turn them into one-off purchases. Failing here, with the fix in
 * the message, is cheaper than finding that out from a customer.
 */
final class OrderItemMustCarrySubscriptionPlanPass implements CompilerPassInterface
{
    private const ORDER_ITEM_CLASS_PARAMETER = 'sylius.model.order_item.class';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::ORDER_ITEM_CLASS_PARAMETER)) {
            return;
        }

        $orderItemClass = $container->getParameterBag()->resolveValue(
            $container->getParameter(self::ORDER_ITEM_CLASS_PARAMETER),
        );

        if (\is_string($orderItemClass) && is_a($orderItemClass, SubscriptionPlanAwareInterface::class, true)) {
            return;
        }

        throw new LogicException(\sprintf(
            'The order item class "%s" does not implement %s, so it cannot hold the subscription plan a customer chooses. '
            . 'Make your OrderItem entity implement that interface and use %s, point "sylius_order.resources.order_item.classes.model" at it, '
            . 'and run the migrations. See the plugin\'s README, "Installation".',
            \is_string($orderItemClass) ? $orderItemClass : get_debug_type($orderItemClass),
            SubscriptionPlanAwareInterface::class,
            SubscriptionPlanAwareTrait::class,
        ));
    }
}
