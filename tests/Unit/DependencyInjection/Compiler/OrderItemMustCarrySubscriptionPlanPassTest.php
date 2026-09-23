<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Unit\DependencyInjection\Compiler;

use JpmMartin\SyliusSubscriptionPlugin\DependencyInjection\Compiler\OrderItemMustCarrySubscriptionPlanPass;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderItem as SyliusOrderItem;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Entity\OrderItem as OrderItemCarryingAPlan;

final class OrderItemMustCarrySubscriptionPlanPassTest extends TestCase
{
    public function testTheContainerDoesNotCompileWhenTheOrderItemCannotHoldAPlan(): void
    {
        $container = $this->containerWithOrderItem(SyliusOrderItem::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(\sprintf('The order item class "%s" does not implement', SyliusOrderItem::class));
        $this->expectExceptionMessage('SubscriptionPlanAwareTrait');
        $this->expectExceptionMessage('"Installation"');

        $container->compile();
    }

    public function testTheContainerCompilesWhenTheOrderItemCarriesThePlan(): void
    {
        $container = $this->containerWithOrderItem(OrderItemCarryingAPlan::class);

        $container->compile();

        self::assertSame(OrderItemCarryingAPlan::class, $container->getParameter('sylius.model.order_item.class'));
    }

    public function testTheOrderItemClassIsReadThroughOtherParameters(): void
    {
        $container = $this->containerWithOrderItem('%app.order_item%');
        $container->setParameter('app.order_item', SyliusOrderItem::class);

        $this->expectException(LogicException::class);

        $container->compile();
    }

    public function testItStaysOutOfTheWayOfAContainerWithoutOrders(): void
    {
        $container = new ContainerBuilder();
        $container->addCompilerPass(new OrderItemMustCarrySubscriptionPlanPass());

        $container->compile();

        self::assertFalse($container->hasParameter('sylius.model.order_item.class'));
    }

    private function containerWithOrderItem(string $orderItemClass): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('sylius.model.order_item.class', $orderItemClass);
        $container->addCompilerPass(new OrderItemMustCarrySubscriptionPlanPass());

        return $container;
    }
}
