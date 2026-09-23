<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\OrderProcessing;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use JpmMartin\SyliusSubscriptionPlugin\Factory\SubscriptionPlanFactoryInterface;
use Sylius\Behat\Context\Hook\DoctrineORMContext;
use Sylius\Behat\Context\Setup\ChannelContext;
use Sylius\Behat\Context\Setup\ProductContext;
use Sylius\Behat\Context\Setup\PromotionContext;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\PromotionInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The subscriber price, through Sylius's whole order processing: the plugin's processor has to run
 * after Sylius sets the variant price and before promotions, which only the real chain shows.
 */
final class SubscriptionPlanPriceTest extends KernelTestCase
{
    private ChannelInterface $channel;

    private ProductVariantInterface $variant;

    private SubscriptionPlanInterface $plan;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var DoctrineORMContext $database */
        $database = $container->get('sylius.behat.context.hook.doctrine_orm');
        $database->purgeDatabase();

        /** @var ChannelContext $channels */
        $channels = $container->get('sylius.behat.context.setup.channel');
        $channels->storeOperatesOnASingleChannelInUnitedStates();

        /** @var ProductContext $products */
        $products = $container->get('sylius.behat.context.setup.product');
        $products->storeHasAProductPricedAt('Coffee', 10000);

        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = $container->get('sylius.behat.shared_storage');
        /** @var ChannelInterface $channel */
        $channel = $sharedStorage->get('channel');
        $this->channel = $channel;
        /** @var ProductInterface $product */
        $product = $sharedStorage->get('product');
        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariantInterface::class, $variant);
        $this->variant = $variant;

        /** @var SubscriptionPlanFactoryInterface $planFactory */
        $planFactory = $container->get('jpm_martin_sylius_subscription.factory.subscription_plan');
        $this->plan = $planFactory->createForVariant($this->variant);
        $this->plan->setCode('COFFEE_MONTHLY');
        $this->plan->setName('Monthly');
        $this->plan->setIntervalCount(1);
        $this->plan->setIntervalUnit(SubscriptionIntervalUnit::Month);
        $this->plan->setDiscountPercentage(10);

        /** @var RepositoryInterface<SubscriptionPlanInterface> $plans */
        $plans = $container->get('jpm_martin_sylius_subscription.repository.subscription_plan');
        $plans->add($this->plan);
    }

    public function testASubscriptionLineCostsTheVariantPriceLessThePlanDiscount(): void
    {
        $cart = $this->cartWith($item = $this->line($this->plan));

        $this->process($cart);

        self::assertSame(9000, $item->getUnitPrice());
        self::assertSame(10000, $item->getOriginalUnitPrice());
        self::assertSame(9000, $cart->getTotal());
    }

    public function testPromotionsApplyToTheSubscriberPrice(): void
    {
        $container = self::getContainer();
        /** @var PromotionContext $promotions */
        $promotions = $container->get('sylius.behat.context.setup.promotion');
        $promotions->thereIsPromotion('Autumn');
        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = $container->get('sylius.behat.shared_storage');
        /** @var PromotionInterface $promotion */
        $promotion = $sharedStorage->get('promotion');
        $promotions->itGivesPercentageDiscountToEveryOrder($promotion, 0.1);

        $cart = $this->cartWith($item = $this->line($this->plan));

        $this->process($cart);

        self::assertSame(9000, $item->getUnitPrice());
        self::assertSame(8100, $cart->getTotal());
    }

    public function testAOneOffLineKeepsTheVariantPrice(): void
    {
        $cart = $this->cartWith($item = $this->line(null));

        $this->process($cart);

        self::assertSame(10000, $item->getUnitPrice());
    }

    public function testAnImmutableLineKeepsThePriceItWasGiven(): void
    {
        $item = $this->line($this->plan);
        $item->setUnitPrice(7777);
        $item->setImmutable(true);
        $cart = $this->cartWith($item);

        $this->process($cart);

        self::assertSame(7777, $item->getUnitPrice());
    }

    private function line(?SubscriptionPlanInterface $plan): OrderItemInterface
    {
        /** @var FactoryInterface<OrderItemInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.order_item');
        $item = $factory->createNew();
        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $item);
        $item->setVariant($this->variant);
        $item->setSubscriptionPlan($plan);

        /** @var OrderItemQuantityModifierInterface $quantityModifier */
        $quantityModifier = self::getContainer()->get('sylius.modifier.order_item_quantity');
        $quantityModifier->modify($item, 1);

        return $item;
    }

    private function cartWith(OrderItemInterface $item): OrderInterface
    {
        /** @var FactoryInterface<OrderInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.order');
        $cart = $factory->createNew();
        $cart->setChannel($this->channel);
        $cart->setCurrencyCode('USD');
        $cart->setLocaleCode('en_US');
        $cart->addItem($item);

        return $cart;
    }

    private function process(OrderInterface $cart): void
    {
        /** @var OrderProcessorInterface $processor */
        $processor = self::getContainer()->get('sylius.order_processing.order_processor');
        $processor->process($cart);
    }
}
