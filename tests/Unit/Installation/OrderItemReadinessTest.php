<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Unit\Installation;

use JpmMartin\SyliusSubscriptionPlugin\Installation\OrderItemReadiness;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderItem as SyliusOrderItem;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Entity\OrderItem as OrderItemCarryingAPlan;

final class OrderItemReadinessTest extends TestCase
{
    public function testAStoreWhoseOrderItemCarriesThePlanIsReady(): void
    {
        $readiness = new OrderItemReadiness(OrderItemCarryingAPlan::class);

        self::assertTrue($readiness->isReady());
        self::assertSame(OrderItemCarryingAPlan::class, $readiness->orderItemClass());
    }

    public function testAStoreStillOnSyliussOwnOrderItemIsNot(): void
    {
        self::assertFalse((new OrderItemReadiness(SyliusOrderItem::class))->isReady());
    }

    public function testAClassThatDoesNotExistIsNotReady(): void
    {
        self::assertFalse((new OrderItemReadiness('App\Entity\Order\NoSuchOrderItem'))->isReady());
    }
}
