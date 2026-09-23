<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Frequency;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use JpmMartin\SyliusSubscriptionPlugin\Factory\SubscriptionPlanFactoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\CartRepeaterInterface;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\RepeatableVariantsInterface;
use Sylius\Behat\Context\Hook\DoctrineORMContext;
use Sylius\Behat\Context\Setup\ChannelContext;
use Sylius\Behat\Context\Setup\ProductContext;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "Repeat this cart" through Sylius's whole order processing: the plugin's processor gives the cart's
 * frequency to its lines before the subscriber price, which only the real chain shows.
 */
final class RepeatingACartTest extends KernelTestCase
{
    private ChannelInterface $channel;

    /** @var array<string, ProductVariantInterface> */
    private array $variants = [];

    private SubscriptionFrequencyInterface $monthly;

    private CartRepeaterInterface $cartRepeater;

    private EntityManagerInterface $entityManager;

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
        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = $container->get('sylius.behat.shared_storage');
        /** @var ChannelInterface $channel */
        $channel = $sharedStorage->get('channel');
        $this->channel = $channel;

        /** @var ProductContext $products */
        $products = $container->get('sylius.behat.context.setup.product');
        foreach (['Coffee' => 2000, 'Tea' => 1000, 'Honey' => 800, 'Gift card' => 5000] as $name => $price) {
            $products->storeHasAProductPricedAt($name, $price, $this->channel);
            /** @var ProductInterface $product */
            $product = $sharedStorage->get('product');
            $variant = $product->getVariants()->first();
            self::assertInstanceOf(ProductVariantInterface::class, $variant);
            $this->variants[$name] = $variant;
        }

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get('doctrine.orm.entity_manager');
        $this->entityManager = $entityManager;

        /** @var RepeatableVariantsInterface $repeatableVariants */
        $repeatableVariants = $container->get(RepeatableVariantsInterface::class);
        foreach (['Coffee', 'Tea', 'Honey'] as $name) {
            $repeatableVariants->markRepeatable($this->variants[$name], true);
        }

        $this->monthly = $this->frequency('MONTHLY', 1, SubscriptionIntervalUnit::Month, 5, $this->channel);
        $this->entityManager->flush();

        /** @var CartRepeaterInterface $cartRepeater */
        $cartRepeater = $container->get(CartRepeaterInterface::class);
        $this->cartRepeater = $cartRepeater;
    }

    public function testRepeatingACartRepeatsItsOneOffLinesAtTheFrequencyDiscount(): void
    {
        $cart = $this->cartWith($coffee = $this->line('Coffee'), $tea = $this->line('Tea'));

        $this->repeat($cart, $this->monthly);

        self::assertSame($this->monthly, $this->frequencyOf($coffee));
        self::assertSame($this->monthly, $this->frequencyOf($tea));
        self::assertSame(1900, $coffee->getUnitPrice());
        self::assertSame(950, $tea->getUnitPrice());
        self::assertSame(2850, $cart->getTotal());
        self::assertSame($this->monthly, $this->cartRepeater->getFrequency($cart));
    }

    public function testALineAddedLaterIsRepeatedToo(): void
    {
        $cart = $this->cartWith($this->line('Coffee'));
        $this->repeat($cart, $this->monthly);

        /** @var OrderModifierInterface $orderModifier */
        $orderModifier = self::getContainer()->get('sylius.modifier.order');
        $orderModifier->addToOrder($cart, $honey = $this->line('Honey'));
        $this->entityManager->flush();

        self::assertSame($this->monthly, $this->frequencyOf($honey));
        self::assertSame(760, $honey->getUnitPrice());
    }

    public function testALineWithAPlanKeepsItsPlan(): void
    {
        $plan = $this->plan('COFFEE_WEEKLY', $this->variants['Coffee'], 10);
        $cart = $this->cartWith($coffee = $this->line('Coffee', $plan), $tea = $this->line('Tea'));

        $this->repeat($cart, $this->monthly);

        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $coffee);
        self::assertSame($plan, $coffee->getSubscriptionPlan());
        self::assertNull($coffee->getSubscriptionFrequency());
        self::assertSame(1800, $coffee->getUnitPrice());
        self::assertSame($this->monthly, $this->frequencyOf($tea));
    }

    public function testAVariantThatCannotBeRepeatedIsBoughtOnce(): void
    {
        $cart = $this->cartWith($coffee = $this->line('Coffee'), $giftCard = $this->line('Gift card'));

        $this->repeat($cart, $this->monthly);

        self::assertSame($this->monthly, $this->frequencyOf($coffee));
        self::assertNull($this->frequencyOf($giftCard));
        self::assertSame(5000, $giftCard->getUnitPrice());
    }

    public function testStoppingRepeatingACartBringsItsLinesBackToTheirPrice(): void
    {
        $cart = $this->cartWith($coffee = $this->line('Coffee'));
        $this->repeat($cart, $this->monthly);

        $this->cartRepeater->stopRepeating($cart);
        $this->process($cart);
        $this->entityManager->flush();

        self::assertNull($this->frequencyOf($coffee));
        self::assertSame(2000, $coffee->getUnitPrice());
        self::assertNull($this->cartRepeater->getFrequency($cart));
    }

    public function testADisabledFrequencyStopsTheCartBeingRepeated(): void
    {
        $cart = $this->cartWith($coffee = $this->line('Coffee'));
        $this->repeat($cart, $this->monthly);

        $this->monthly->disable();
        $this->process($cart);
        $this->entityManager->flush();

        self::assertNull($this->frequencyOf($coffee));
        self::assertSame(2000, $coffee->getUnitPrice());
        self::assertNull($this->cartRepeater->getFrequency($cart));
    }

    public function testAFrequencyTakenOffTheCartsChannelStopsTheCartBeingRepeated(): void
    {
        $cart = $this->cartWith($coffee = $this->line('Coffee'));
        $this->repeat($cart, $this->monthly);

        $this->monthly->removeChannel($this->channel);
        $this->process($cart);
        $this->entityManager->flush();

        self::assertNull($this->frequencyOf($coffee));
        self::assertNull($this->cartRepeater->getFrequency($cart));
    }

    public function testOnlyTheEnabledFrequenciesOfTheCartsChannelAreOffered(): void
    {
        $disabled = $this->frequency('WEEKLY', 1, SubscriptionIntervalUnit::Week, 0, $this->channel);
        $disabled->disable();
        $elsewhere = $this->frequency('FRANCE_MONTHLY', 1, SubscriptionIntervalUnit::Month, 5, $this->otherChannel());
        $this->entityManager->flush();
        $cart = $this->cartWith($this->line('Coffee'));

        self::assertSame([$this->monthly], $this->cartRepeater->getOfferedFrequencies($cart));
        self::assertFalse($this->cartRepeater->isOffered($cart, $disabled));
        self::assertFalse($this->cartRepeater->isOffered($cart, $elsewhere));

        $this->expectException(\InvalidArgumentException::class);
        $this->cartRepeater->repeat($cart, $elsewhere);
    }

    public function testACartIsOfferedRepeatingOnlyWithALineThatCouldBeRepeated(): void
    {
        $plan = $this->plan('COFFEE_WEEKLY', $this->variants['Coffee'], 10);

        self::assertFalse($this->cartRepeater->hasRepeatableLines($this->cartWith($this->line('Gift card'), $this->line('Coffee', $plan))));
        self::assertTrue($this->cartRepeater->hasRepeatableLines($this->cartWith($this->line('Gift card'), $this->line('Tea'))));
    }

    private function frequencyOf(OrderItemInterface $item): ?SubscriptionFrequencyInterface
    {
        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $item);

        return $item->getSubscriptionFrequency();
    }

    private function repeat(OrderInterface $cart, SubscriptionFrequencyInterface $frequency): void
    {
        $this->cartRepeater->repeat($cart, $frequency);
        $this->process($cart);
        $this->entityManager->flush();
    }

    private function frequency(string $code, int $intervalCount, SubscriptionIntervalUnit $intervalUnit, int $discountPercentage, ChannelInterface $channel): SubscriptionFrequencyInterface
    {
        /** @var FactoryInterface<SubscriptionFrequencyInterface> $factory */
        $factory = self::getContainer()->get('jpm_martin_sylius_subscription.factory.subscription_frequency');
        $frequency = $factory->createNew();
        $frequency->setCode($code);
        $frequency->setName($code);
        $frequency->setIntervalCount($intervalCount);
        $frequency->setIntervalUnit($intervalUnit);
        $frequency->setDiscountPercentage($discountPercentage);
        $frequency->addChannel($channel);
        $this->entityManager->persist($frequency);

        return $frequency;
    }

    private function plan(string $code, ProductVariantInterface $variant, int $discountPercentage): SubscriptionPlanInterface
    {
        /** @var SubscriptionPlanFactoryInterface $factory */
        $factory = self::getContainer()->get('jpm_martin_sylius_subscription.factory.subscription_plan');
        $plan = $factory->createForVariant($variant);
        $plan->setCode($code);
        $plan->setName($code);
        $plan->setIntervalCount(1);
        $plan->setIntervalUnit(SubscriptionIntervalUnit::Week);
        $plan->setDiscountPercentage($discountPercentage);
        $this->entityManager->persist($plan);
        $this->entityManager->flush();

        return $plan;
    }

    private function otherChannel(): ChannelInterface
    {
        /** @var ChannelContext $channels */
        $channels = self::getContainer()->get('sylius.behat.context.setup.channel');
        $channels->theStoreOperatesOnAChannelNamed('France', 'EUR', 'fr.example.com');
        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = self::getContainer()->get('sylius.behat.shared_storage');
        /** @var ChannelInterface $channel */
        $channel = $sharedStorage->get('channel');

        return $channel;
    }

    private function line(string $product, ?SubscriptionPlanInterface $plan = null): OrderItemInterface
    {
        /** @var FactoryInterface<OrderItemInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.order_item');
        $item = $factory->createNew();
        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $item);
        $item->setVariant($this->variants[$product]);
        $item->setSubscriptionPlan($plan);

        /** @var OrderItemQuantityModifierInterface $quantityModifier */
        $quantityModifier = self::getContainer()->get('sylius.modifier.order_item_quantity');
        $quantityModifier->modify($item, 1);

        return $item;
    }

    private function cartWith(OrderItemInterface ...$items): OrderInterface
    {
        /** @var FactoryInterface<OrderInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.order');
        $cart = $factory->createNew();
        $cart->setChannel($this->channel);
        $cart->setCurrencyCode('USD');
        $cart->setLocaleCode('en_US');
        foreach ($items as $item) {
            $cart->addItem($item);
        }
        $this->process($cart);
        $this->entityManager->persist($cart);
        $this->entityManager->flush();

        return $cart;
    }

    private function process(OrderInterface $cart): void
    {
        /** @var OrderProcessorInterface $processor */
        $processor = self::getContainer()->get('sylius.order_processing.order_processor');
        $processor->process($cart);
    }
}
