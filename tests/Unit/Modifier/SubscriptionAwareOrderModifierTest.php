<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Unit\Modifier;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlan;
use JpmMartin\SyliusSubscriptionPlugin\Modifier\SubscriptionAwareOrderModifier;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderItem as SyliusOrderItem;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Order\Factory\OrderItemUnitFactory;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifier;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Entity\OrderItem;

final class SubscriptionAwareOrderModifierTest extends TestCase
{
    private OrderModifierInterface&MockObject $decorated;

    private OrderProcessorInterface&MockObject $orderProcessor;

    private OrderItemQuantityModifier $quantityModifier;

    private SubscriptionAwareOrderModifier $modifier;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        $this->decorated = $this->createMock(OrderModifierInterface::class);
        $this->orderProcessor = $this->createMock(OrderProcessorInterface::class);
        $this->quantityModifier = new OrderItemQuantityModifier(new OrderItemUnitFactory(OrderItemUnit::class));
        $this->modifier = new SubscriptionAwareOrderModifier($this->decorated, $this->orderProcessor, $this->quantityModifier);
        $this->variant = new ProductVariant();
    }

    public function testTwoOneOffLinesOfTheSameVariantAreMerged(): void
    {
        $existing = $this->item($this->variant, 1);
        $cart = $this->cartWith($existing);

        $this->orderProcessor->expects(self::once())->method('process')->with($cart);
        $this->modifier->addToOrder($cart, $this->item($this->variant, 2));

        self::assertCount(1, $cart->getItems());
        self::assertSame(3, $existing->getQuantity());
    }

    public function testASubscriptionLineIsNotMergedIntoAOneOffLineOfTheSameVariant(): void
    {
        $existing = $this->item($this->variant, 1);
        $cart = $this->cartWith($existing);
        $subscriptionLine = $this->item($this->variant, 1, $this->plan('MONTHLY'));

        $this->modifier->addToOrder($cart, $subscriptionLine);

        self::assertCount(2, $cart->getItems());
        self::assertTrue($cart->hasItem($subscriptionLine));
        self::assertSame(1, $existing->getQuantity());
    }

    public function testTwoLinesOnTheSamePlanAreMerged(): void
    {
        $plan = $this->plan('MONTHLY');
        $existing = $this->item($this->variant, 1, $plan);
        $cart = $this->cartWith($existing);

        $this->modifier->addToOrder($cart, $this->item($this->variant, 1, $plan));

        self::assertCount(1, $cart->getItems());
        self::assertSame(2, $existing->getQuantity());
    }

    public function testTwoLinesOnDifferentPlansAreKeptApart(): void
    {
        $existing = $this->item($this->variant, 1, $this->plan('MONTHLY'));
        $cart = $this->cartWith($existing);

        $this->modifier->addToOrder($cart, $this->item($this->variant, 1, $this->plan('QUARTERLY')));

        self::assertCount(2, $cart->getItems());
        self::assertSame(1, $existing->getQuantity());
    }

    public function testALineIsMergedIntoTheLineOnItsPlanEvenWhenAOneOffLineComesFirst(): void
    {
        $plan = $this->plan('MONTHLY');
        $oneOff = $this->item($this->variant, 1);
        $subscription = $this->item($this->variant, 1, $plan);
        $cart = $this->cartWith($oneOff, $subscription);

        $this->modifier->addToOrder($cart, $this->item($this->variant, 1, $plan));

        self::assertCount(2, $cart->getItems());
        self::assertSame(1, $oneOff->getQuantity());
        self::assertSame(2, $subscription->getQuantity());
    }

    public function testTheSamePlanLoadedTwiceIsRecognisedByItsId(): void
    {
        $existing = $this->item($this->variant, 1, $this->plan('MONTHLY', 7));
        $cart = $this->cartWith($existing);

        $this->modifier->addToOrder($cart, $this->item($this->variant, 1, $this->plan('MONTHLY', 7)));

        self::assertCount(1, $cart->getItems());
        self::assertSame(2, $existing->getQuantity());
    }

    public function testLinesOfDifferentVariantsAreKeptApart(): void
    {
        $existing = $this->item($this->variant, 1);
        $cart = $this->cartWith($existing);

        $this->modifier->addToOrder($cart, $this->item(new ProductVariant(), 1));

        self::assertCount(2, $cart->getItems());
    }

    public function testAnItemThatCannotCarryAPlanIsLeftToSylius(): void
    {
        $cart = new Order();
        $item = new SyliusOrderItem();

        $this->decorated->expects(self::once())->method('addToOrder')->with($cart, $item);
        $this->orderProcessor->expects(self::never())->method('process');

        $this->modifier->addToOrder($cart, $item);
    }

    public function testRemovingALineIsLeftToSylius(): void
    {
        $item = $this->item($this->variant, 1);
        $cart = $this->cartWith($item);

        $this->decorated->expects(self::once())->method('removeFromOrder')->with($cart, $item);

        $this->modifier->removeFromOrder($cart, $item);
    }

    private function item(ProductVariant $variant, int $quantity, ?SubscriptionPlan $plan = null): OrderItem
    {
        $item = new OrderItem();
        $item->setVariant($variant);
        $item->setSubscriptionPlan($plan);
        $this->quantityModifier->modify($item, $quantity);

        return $item;
    }

    private function cartWith(OrderItem ...$items): Order
    {
        $cart = new Order();
        foreach ($items as $item) {
            $cart->addItem($item);
        }

        return $cart;
    }

    private function plan(string $code, ?int $id = null): SubscriptionPlan
    {
        $plan = new SubscriptionPlan();
        $plan->setCode($code);

        if (null !== $id) {
            (new \ReflectionProperty(SubscriptionPlan::class, 'id'))->setValue($plan, $id);
        }

        return $plan;
    }
}
