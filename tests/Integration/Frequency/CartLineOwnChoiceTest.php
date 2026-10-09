<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Frequency;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\CartItemSubscriptionChoices;
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
 * A line whose subscription was chosen on the line itself (choose-subscription-per-cart-line, D1 and D2): the cart's
 * frequency processor has to leave it alone, as long as it is still valid, independently of whatever the cart's own
 * frequency is doing. "Repeat this cart" overriding a line's own choice, and the line changer that marks a choice in
 * the first place, are covered where those live (CartRepeaterInterface and the line changer's own tests).
 */
final class CartLineOwnChoiceTest extends KernelTestCase
{
    private ChannelInterface $channel;

    /** @var array<string, ProductVariantInterface> */
    private array $variants = [];

    private SubscriptionFrequencyInterface $monthly;

    private SubscriptionFrequencyInterface $biweekly;

    private CartRepeaterInterface $cartRepeater;

    private CartItemSubscriptionChoices $choices;

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
        foreach (['Coffee' => 2000, 'Tea' => 1000, 'Honey' => 800] as $name => $price) {
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

        $this->monthly = $this->frequency('MONTHLY', 1, SubscriptionIntervalUnit::Month, 5);
        $this->biweekly = $this->frequency('BIWEEKLY', 2, SubscriptionIntervalUnit::Week, 10);
        $this->entityManager->flush();

        /** @var CartRepeaterInterface $cartRepeater */
        $cartRepeater = $container->get(CartRepeaterInterface::class);
        $this->cartRepeater = $cartRepeater;

        $this->choices = $container->get('jpm_martin_sylius_subscription.frequency.cart_item_subscription_choices');
    }

    public function testALinesOwnFrequencySurvivesReprocessingAnUnrepeatedCart(): void
    {
        $cart = $this->cartWith($tea = $this->line('Tea'));
        $this->chooseOnLine($tea, $this->biweekly);
        $this->process($cart);
        $this->entityManager->flush();

        self::assertSame($this->biweekly, $this->frequencyOf($tea));
        self::assertSame(900, $tea->getUnitPrice());
        self::assertNull($this->cartRepeater->getFrequency($cart));

        // Processed again, as the cart is on every page load: the choice is not transient.
        $this->process($cart);
        $this->entityManager->flush();
        self::assertSame($this->biweekly, $this->frequencyOf($tea));
    }

    public function testALinesOwnOneTimeChoiceIsNotOverriddenByTheCartsFrequency(): void
    {
        $cart = $this->cartWith($coffee = $this->line('Coffee'), $tea = $this->line('Tea'));
        $this->choices->mark($tea);
        $this->entityManager->flush();

        $this->repeat($cart, $this->monthly);

        self::assertSame($this->monthly, $this->frequencyOf($coffee));
        self::assertNull($this->frequencyOf($tea));
        self::assertSame(1000, $tea->getUnitPrice());
    }

    public function testALinesOwnFrequencyDoesNotHaveToMatchTheCarts(): void
    {
        $cart = $this->cartWith($coffee = $this->line('Coffee'), $tea = $this->line('Tea'));
        $this->chooseOnLine($tea, $this->biweekly);
        $this->repeat($cart, $this->monthly);

        self::assertSame($this->monthly, $this->frequencyOf($coffee));
        self::assertSame($this->biweekly, $this->frequencyOf($tea));
        self::assertSame(900, $tea->getUnitPrice());
    }

    public function testDisablingALinesOwnFrequencyBuysItOnceAndKeepsTheMark(): void
    {
        $cart = $this->cartWith($coffee = $this->line('Coffee'), $tea = $this->line('Tea'));
        $this->chooseOnLine($tea, $this->biweekly);
        $this->repeat($cart, $this->monthly);

        $this->biweekly->disable();
        $this->process($cart);
        $this->entityManager->flush();

        self::assertNull($this->frequencyOf($tea));
        self::assertSame(1000, $tea->getUnitPrice());
        self::assertTrue($this->choices->has($tea));
        // Unaffected: coffee still follows the cart's own, still valid, frequency.
        self::assertSame($this->monthly, $this->frequencyOf($coffee));

        // The mark stays: tea does not inherit the cart's frequency on the next reprocessing either.
        $this->process($cart);
        $this->entityManager->flush();
        self::assertNull($this->frequencyOf($tea));
    }

    public function testDisablingTheCartsFrequencyDoesNotTouchALinesOwnFrequency(): void
    {
        $cart = $this->cartWith($coffee = $this->line('Coffee'), $tea = $this->line('Tea'));
        $this->chooseOnLine($tea, $this->biweekly);
        $this->repeat($cart, $this->monthly);

        $this->monthly->disable();
        $this->process($cart);
        $this->entityManager->flush();

        self::assertNull($this->frequencyOf($coffee));
        self::assertNull($this->cartRepeater->getFrequency($cart));
        // Unaffected: tea's own choice is independent of the cart's frequency.
        self::assertSame($this->biweekly, $this->frequencyOf($tea));
        self::assertSame(900, $tea->getUnitPrice());
    }

    public function testALineAddedAfterAnotherChoseOneTimeFollowsTheCartOnItsOwn(): void
    {
        $cart = $this->cartWith($coffee = $this->line('Coffee'), $tea = $this->line('Tea'));
        $this->repeat($cart, $this->monthly);
        $this->chooseOneTimeOnLine($tea);
        $this->process($cart);
        $this->entityManager->flush();
        self::assertNull($this->frequencyOf($tea));

        /** @var OrderModifierInterface $orderModifier */
        $orderModifier = self::getContainer()->get('sylius.modifier.order');
        $orderModifier->addToOrder($cart, $honey = $this->line('Honey'));
        $this->entityManager->flush();

        self::assertSame($this->monthly, $this->frequencyOf($honey));
        self::assertNull($this->frequencyOf($tea));
        self::assertSame($this->monthly, $this->frequencyOf($coffee));
    }

    private function chooseOnLine(OrderItemInterface $item, SubscriptionFrequencyInterface $frequency): void
    {
        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $item);
        $this->choices->mark($item);
        $item->setSubscriptionFrequency($frequency);
        $this->entityManager->flush();
    }

    /** What the (not yet built) line changer does for "buy once": mark the choice, with no frequency. */
    private function chooseOneTimeOnLine(OrderItemInterface $item): void
    {
        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $item);
        $this->choices->mark($item);
        $item->setSubscriptionFrequency(null);
        $this->entityManager->flush();
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

    private function frequency(string $code, int $intervalCount, SubscriptionIntervalUnit $intervalUnit, int $discountPercentage): SubscriptionFrequencyInterface
    {
        /** @var FactoryInterface<SubscriptionFrequencyInterface> $factory */
        $factory = self::getContainer()->get('jpm_martin_sylius_subscription.factory.subscription_frequency');
        $frequency = $factory->createNew();
        $frequency->setCode($code);
        $frequency->setName($code);
        $frequency->setIntervalCount($intervalCount);
        $frequency->setIntervalUnit($intervalUnit);
        $frequency->setDiscountPercentage($discountPercentage);
        $frequency->addChannel($this->channel);
        $this->entityManager->persist($frequency);

        return $frequency;
    }

    private function line(string $product): OrderItemInterface
    {
        /** @var FactoryInterface<OrderItemInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.order_item');
        $item = $factory->createNew();
        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $item);
        $item->setVariant($this->variants[$product]);

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
