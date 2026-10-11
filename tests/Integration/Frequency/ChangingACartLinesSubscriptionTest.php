<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Frequency;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\CartItemSubscriptionChoiceInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use JpmMartin\SyliusSubscriptionPlugin\Factory\SubscriptionPlanFactoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\CartItemSubscriptionChangerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\CartItemSubscriptionChoices;
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
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Changing what a single cart line is bought as (choose-subscription-per-cart-line, D3 and D4): the options a line
 * is offered, moving between buying once, a store frequency and a plan, and merging into a matching line.
 */
final class ChangingACartLinesSubscriptionTest extends KernelTestCase
{
    private ChannelInterface $channel;

    /** @var array<string, ProductVariantInterface> */
    private array $variants = [];

    private SubscriptionFrequencyInterface $monthly;

    private SubscriptionFrequencyInterface $biweekly;

    private CartItemSubscriptionChangerInterface $changer;

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
        foreach (['Coffee' => 2000, 'Tea' => 1000, 'Gift card' => 5000] as $name => $price) {
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
        foreach (['Coffee', 'Tea'] as $name) {
            $repeatableVariants->markRepeatable($this->variants[$name], true);
        }

        $this->monthly = $this->frequency('MONTHLY', 1, SubscriptionIntervalUnit::Month, 5);
        $this->biweekly = $this->frequency('BIWEEKLY', 2, SubscriptionIntervalUnit::Week, 10);
        $this->entityManager->flush();

        $this->changer = $container->get(CartItemSubscriptionChangerInterface::class);
        $this->choices = $container->get('jpm_martin_sylius_subscription.frequency.cart_item_subscription_choices');
    }

    public function testRepeatingASingleLine(): void
    {
        $cart = $this->cartWith($coffee = $this->line('Coffee'), $tea = $this->line('Tea'));

        $this->changer->change($tea, null, $this->biweekly);
        $this->process($cart);
        $this->entityManager->flush();

        self::assertSame($this->biweekly, $this->frequencyOf($tea));
        self::assertSame(900, $tea->getUnitPrice());
        self::assertNull($this->frequencyOf($coffee));
        self::assertSame(2000, $coffee->getUnitPrice());
    }

    public function testChangingFromOnePlanToAnother(): void
    {
        $monthly = $this->plan('COFFEE_MONTHLY', $this->variants['Coffee'], 1, SubscriptionIntervalUnit::Month, 10);
        $weekly = $this->plan('COFFEE_WEEKLY', $this->variants['Coffee'], 1, SubscriptionIntervalUnit::Week, 20);
        $cart = $this->cartWith($coffee = $this->line('Coffee', $monthly));

        $this->changer->change($coffee, $weekly, null);
        $this->process($cart);
        $this->entityManager->flush();

        self::assertSame($weekly, $coffee->getSubscriptionPlan());
        self::assertNull($coffee->getSubscriptionFrequency());
        self::assertSame(1600, $coffee->getUnitPrice());
    }

    public function testChangingFromAPlanToAStoreFrequency(): void
    {
        $plan = $this->plan('COFFEE_MONTHLY', $this->variants['Coffee'], 1, SubscriptionIntervalUnit::Month, 10);
        $cart = $this->cartWith($coffee = $this->line('Coffee', $plan));

        $this->changer->change($coffee, null, $this->biweekly);
        $this->process($cart);
        $this->entityManager->flush();

        self::assertNull($coffee->getSubscriptionPlan());
        self::assertSame($this->biweekly, $coffee->getSubscriptionFrequency());
        self::assertSame(1800, $coffee->getUnitPrice());
    }

    public function testChangingFromAPlanToOneTime(): void
    {
        $plan = $this->plan('COFFEE_MONTHLY', $this->variants['Coffee'], 1, SubscriptionIntervalUnit::Month, 10);
        $cart = $this->cartWith($coffee = $this->line('Coffee', $plan));

        $this->changer->change($coffee, null, null);
        $this->process($cart);
        $this->entityManager->flush();

        self::assertNull($coffee->getSubscriptionPlan());
        self::assertNull($coffee->getSubscriptionFrequency());
        self::assertSame(2000, $coffee->getUnitPrice());
    }

    public function testChangingToAPlanWithAFreeTrial(): void
    {
        $trial = $this->plan('COFFEE_TRIAL', $this->variants['Coffee'], 1, SubscriptionIntervalUnit::Month, 10);
        $trial->setTrialDays(14);
        $this->entityManager->flush();
        $cart = $this->cartWith($coffee = $this->line('Coffee'));

        $this->changer->change($coffee, $trial, null);
        $this->process($cart);
        $this->entityManager->flush();

        self::assertSame($trial, $coffee->getSubscriptionPlan());
        self::assertSame(0, $coffee->getUnitPrice());
        self::assertSame(14, $coffee->getSubscriptionTrialDays());
    }

    public function testOptionsOfAVariantThatCannotBeRepeatedOrPlanned(): void
    {
        $giftCard = $this->line('Gift card');
        $this->cartWith($giftCard);

        $options = $this->changer->getOptions($giftCard);

        self::assertCount(1, $options);
        self::assertNull($options[0]->plan);
        self::assertNull($options[0]->frequency);
        self::assertTrue($options[0]->current);
    }

    public function testAnOptionTheLineDoesNotHaveIsRejected(): void
    {
        $giftCard = $this->line('Gift card');
        $this->cartWith($giftCard);

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->changer->change($giftCard, null, $this->monthly);
        } finally {
            self::assertNull($this->frequencyOf($giftCard));
            self::assertFalse($this->choices->has($giftCard));
        }
    }

    public function testThePlanAndTheFrequencyCannotBeGivenTogether(): void
    {
        $plan = $this->plan('COFFEE_MONTHLY', $this->variants['Coffee'], 1, SubscriptionIntervalUnit::Month, 10);
        $coffee = $this->line('Coffee');
        $this->cartWith($coffee);

        $this->expectException(\InvalidArgumentException::class);
        $this->changer->change($coffee, $plan, $this->monthly);
    }

    public function testTheChangeMergesIntoAMatchingLineAndTheLosersNewMarkNeverReachesTheDatabase(): void
    {
        $oneOff = $this->line('Coffee', null, 2);
        $alreadyBiweekly = $this->line('Coffee', null, 1);
        $cart = $this->cartWith($oneOff, $alreadyBiweekly);
        // The survivor's own, pre-existing mark: untouched by the merge, and the only one that should remain.
        $this->choices->mark($alreadyBiweekly);
        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $alreadyBiweekly);
        $alreadyBiweekly->setSubscriptionFrequency($this->biweekly);
        $this->entityManager->flush();

        // change() marks $oneOff too, before merging it away: that brand new mark must not reach the database.
        $this->changer->change($oneOff, null, $this->biweekly);
        $this->process($cart);
        $this->entityManager->flush();

        self::assertCount(1, $cart->getItems());
        $survivor = $cart->getItems()->first();
        self::assertInstanceOf(OrderItemInterface::class, $survivor);
        self::assertSame($alreadyBiweekly, $survivor);
        self::assertSame(3, $survivor->getQuantity());
        self::assertSame($this->biweekly, $this->frequencyOf($survivor));

        /** @var list<CartItemSubscriptionChoiceInterface> $rows */
        $rows = $this->entityManager->getRepository(CartItemSubscriptionChoiceInterface::class)->findAll();
        self::assertCount(1, $rows, 'Only the survivor\'s pre-existing mark remains; the loser\'s brand new one never reached the database.');
        self::assertSame($alreadyBiweekly, $rows[0]->getOrderItem());
    }

    private function frequencyOf(OrderItemInterface $item): ?SubscriptionFrequencyInterface
    {
        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $item);

        return $item->getSubscriptionFrequency();
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

    private function plan(string $code, ProductVariantInterface $variant, int $intervalCount, SubscriptionIntervalUnit $intervalUnit, int $discountPercentage): SubscriptionPlanInterface
    {
        /** @var SubscriptionPlanFactoryInterface $factory */
        $factory = self::getContainer()->get('jpm_martin_sylius_subscription.factory.subscription_plan');
        $plan = $factory->createForVariant($variant);
        $plan->setCode($code);
        $plan->setName($code);
        $plan->setIntervalCount($intervalCount);
        $plan->setIntervalUnit($intervalUnit);
        $plan->setDiscountPercentage($discountPercentage);
        $this->entityManager->persist($plan);
        $this->entityManager->flush();

        return $plan;
    }

    private function line(string $product, ?SubscriptionPlanInterface $plan = null, int $quantity = 1): OrderItemInterface
    {
        /** @var FactoryInterface<OrderItemInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.order_item');
        $item = $factory->createNew();
        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $item);
        $item->setVariant($this->variants[$product]);
        $item->setSubscriptionPlan($plan);

        /** @var OrderItemQuantityModifierInterface $quantityModifier */
        $quantityModifier = self::getContainer()->get('sylius.modifier.order_item_quantity');
        $quantityModifier->modify($item, $quantity);

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
