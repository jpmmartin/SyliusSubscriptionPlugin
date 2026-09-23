<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Installation;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OrderItemCarriesSubscriptionPlanTest extends KernelTestCase
{
    public function testTheTestApplicationBootsWithAnOrderItemThatCarriesThePlan(): void
    {
        $container = self::getContainer();

        $orderItemClass = $container->getParameter('sylius.model.order_item.class');

        self::assertIsString($orderItemClass);
        self::assertTrue(is_a($orderItemClass, SubscriptionPlanAwareInterface::class, true));
    }
}
