<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Consent\SubscriptionConsentRecorder;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use JpmMartin\SyliusSubscriptionPlugin\Factory\SubscriptionPlanFactoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\CartRepeaterInterface;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\RepeatableVariantsInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Behat\Context\Hook\CalendarContext as CalendarHookContext;
use Sylius\Behat\Context\Hook\DoctrineORMContext;
use Sylius\Behat\Context\Setup\CalendarContext;
use Sylius\Behat\Context\Setup\ChannelContext;
use Sylius\Behat\Context\Setup\CustomerContext;
use Sylius\Behat\Context\Setup\PaymentContext;
use Sylius\Behat\Context\Setup\ProductContext;
use Sylius\Behat\Context\Setup\ShippingContext;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

/**
 * A store selling Coffee (shipped, monthly with 10% off), Tea (not shipped, every two weeks or
 * monthly) and Honey (shipped, every three months), and orders placed through Sylius's own checkout
 * graph, so whatever happens to the subscriptions comes from the plugin's listeners and not from a
 * call the test makes.
 */
abstract class LifecycleTestCase extends KernelTestCase
{
    protected ChannelInterface $channel;

    protected CustomerInterface $customer;

    protected ShippingMethodInterface $shippingMethod;

    protected PaymentMethodInterface $paymentMethod;

    protected ProductVariantInterface $coffee;

    protected ProductVariantInterface $tea;

    protected SubscriptionPlanInterface $coffeeMonthly;

    protected SubscriptionPlanInterface $teaEveryTwoWeeks;

    protected SubscriptionPlanInterface $teaMonthly;

    protected ProductVariantInterface $honey;

    protected SubscriptionPlanInterface $honeyQuarterly;

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
        /** @var ShippingContext $shipping */
        $shipping = $container->get('sylius.behat.context.setup.shipping');
        $shipping->theStoreShipsEverywhereWith('Free');
        /** @var PaymentContext $payment */
        $payment = $container->get('sylius.behat.context.setup.payment');
        $payment->storeAllowsPaying('Card on file');
        /** @var CustomerContext $customers */
        $customers = $container->get('sylius.behat.context.setup.customer');
        $customers->theStoreHasCustomerWithNameAndEmail('John Doe', 'john@example.com');

        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = $container->get('sylius.behat.shared_storage');
        /** @var ChannelInterface $channel */
        $channel = $sharedStorage->get('channel');
        $this->channel = $channel;
        /** @var ShippingMethodInterface $shippingMethod */
        $shippingMethod = $sharedStorage->get('shipping_method');
        $this->shippingMethod = $shippingMethod;
        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $sharedStorage->get('payment_method');
        $this->paymentMethod = $paymentMethod;
        // Card on file charges through the test store's scripted gateway, by payment requests.
        $gatewayConfig = $paymentMethod->getGatewayConfig();
        self::assertInstanceOf(GatewayConfigInterface::class, $gatewayConfig);
        $gatewayConfig->setFactoryName('scripted');
        $gatewayConfig->setUsePayum(false);
        /** @var CustomerInterface $customer */
        $customer = $sharedStorage->get('customer');
        $this->customer = $customer;

        $this->coffee = $this->variantOfANewProduct('Coffee', 10000);
        $this->tea = $this->variantOfANewProduct('Tea', 5000);
        $this->tea->setShippingRequired(false);
        $this->honey = $this->variantOfANewProduct('Honey', 2000);

        $this->coffeeMonthly = $this->plan($this->coffee, 'COFFEE_MONTHLY', 1, SubscriptionIntervalUnit::Month, 10);
        $this->teaEveryTwoWeeks = $this->plan($this->tea, 'TEA_EVERY_TWO_WEEKS', 2, SubscriptionIntervalUnit::Week, 0);
        $this->teaMonthly = $this->plan($this->tea, 'TEA_MONTHLY', 1, SubscriptionIntervalUnit::Month, 0);
        $this->honeyQuarterly = $this->plan($this->honey, 'HONEY_QUARTERLY', 3, SubscriptionIntervalUnit::Month, 0);
        $this->entityManager()->flush();
    }

    protected function tearDown(): void
    {
        /** @var CalendarHookContext $calendar */
        $calendar = self::getContainer()->get('sylius.behat.context.hook.calendar');
        $calendar->deleteTemporaryDate();

        parent::tearDown();
    }

    /** What the clock service answers from now on, through the same file Sylius's own scenarios use. */
    protected function itIsNow(string $dateTime): void
    {
        /** @var CalendarContext $calendar */
        $calendar = self::getContainer()->get('sylius.behat.context.setup.calendar');
        $calendar->itIsNow($dateTime);
    }

    protected function cart(): OrderInterface
    {
        /** @var FactoryInterface<OrderInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.order');
        $order = $factory->createNew();
        $order->setChannel($this->channel);
        $order->setCurrencyCode('USD');
        $order->setLocaleCode('en_US');
        $order->setCustomer($this->customer);

        return $order;
    }

    protected function addLine(OrderInterface $order, ProductVariantInterface $variant, int $quantity, ?SubscriptionPlanInterface $plan): OrderItemInterface
    {
        /** @var FactoryInterface<OrderItemInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.order_item');
        $item = $factory->createNew();
        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $item);
        $item->setVariant($variant);
        $item->setSubscriptionPlan($plan);

        /** @var OrderItemQuantityModifierInterface $quantityModifier */
        $quantityModifier = self::getContainer()->get('sylius.modifier.order_item_quantity');
        $quantityModifier->modify($item, $quantity);

        /** @var OrderModifierInterface $orderModifier */
        $orderModifier = self::getContainer()->get('sylius.modifier.order');
        $orderModifier->addToOrder($order, $item);

        return $item;
    }

    /** The shop's checkout: the consent is accepted in one request and the order completed in the next. */
    protected function placeWithConsent(OrderInterface $order): void
    {
        $this->entityManager()->persist($order);
        $this->entityManager()->flush();

        /** @var SubscriptionConsentRecorder $consentRecorder */
        $consentRecorder = self::getContainer()->get('jpm_martin_sylius_subscription.consent.recorder');
        $consentRecorder->record($order);
        $this->entityManager()->flush();
        $consentRecorder->reset();

        $this->place($order);
    }

    /** The transitions Sylius's own Behat OrderContext applies, in the same order. */
    protected function place(OrderInterface $order): void
    {
        $order->setShippingAddress($this->address());
        $order->setBillingAddress($this->address());
        $this->entityManager()->persist($order);
        $this->entityManager()->flush();

        /** @var StateMachineInterface $stateMachine */
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');

        $this->apply($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_ADDRESS);
        // Sylius skips shipping by itself when nothing in the order ships.
        if ($stateMachine->can($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING)) {
            foreach ($order->getShipments() as $shipment) {
                $shipment->setMethod($this->shippingMethod);
            }
            $this->apply($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING);
        }
        $order->getLastPayment(PaymentInterface::STATE_CART)?->setMethod($this->paymentMethod);
        $this->apply($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT);
        $this->apply($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_COMPLETE);

        $this->entityManager()->flush();
        self::assertSame(OrderCheckoutStates::STATE_COMPLETED, $order->getCheckoutState());
    }

    /** A customer's order of one Coffee on the monthly plan, placed with consent and not paid yet. */
    protected function placedCoffeeOrder(): OrderInterface
    {
        $order = $this->cart();
        $this->addLine($order, $this->coffee, 1, $this->coffeeMonthly);
        $this->placeWithConsent($order);

        return $order;
    }

    /** A customer's order of one Coffee and one Tea, both monthly, placed with consent and not paid yet: one subscription of two items. */
    protected function placedBatchOrder(): OrderInterface
    {
        $order = $this->cart();
        $this->addLine($order, $this->coffee, 1, $this->coffeeMonthly);
        $this->addLine($order, $this->tea, 1, $this->teaMonthly);
        $this->placeWithConsent($order);

        return $order;
    }

    protected function pay(OrderInterface $order): void
    {
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);
        $this->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
        $this->entityManager()->flush();
    }

    protected function scriptedGateway(): ScriptedGateway
    {
        $gateway = self::getContainer()->get('jpm_martin_sylius_subscription.test.scripted_gateway');
        self::assertInstanceOf(ScriptedGateway::class, $gateway);

        return $gateway;
    }

    /** What the store's scheduler would run, in a fresh entity manager like a new process. */
    protected function runTheCycleCommand(): CommandTester
    {
        $this->entityManager()->clear();

        $application = new Application(self::$kernel ?? self::bootKernel());
        $tester = new CommandTester($application->find('jpm-martin:subscription:process-cycles'));
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        return $tester;
    }

    protected function apply(object $subject, string $graph, string $transition): void
    {
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');
        $stateMachine->apply($subject, $graph, $transition);
    }

    /** A frequency of the store, offered in its channel, whose variants given here can be repeated. */
    protected function storeFrequency(string $code, int $intervalCount, SubscriptionIntervalUnit $unit, int $discount, ProductVariantInterface ...$repeatable): SubscriptionFrequencyInterface
    {
        /** @var FactoryInterface<SubscriptionFrequencyInterface> $factory */
        $factory = self::getContainer()->get('jpm_martin_sylius_subscription.factory.subscription_frequency');
        $frequency = $factory->createNew();
        $frequency->setCode($code);
        $frequency->setName($code);
        $frequency->setIntervalCount($intervalCount);
        $frequency->setIntervalUnit($unit);
        $frequency->setDiscountPercentage($discount);
        $frequency->addChannel($this->channel);
        $this->entityManager()->persist($frequency);

        /** @var RepeatableVariantsInterface $repeatableVariants */
        $repeatableVariants = self::getContainer()->get(RepeatableVariantsInterface::class);
        foreach ($repeatable as $variant) {
            $repeatableVariants->markRepeatable($variant, true);
        }
        $this->entityManager()->flush();

        return $frequency;
    }

    /** "Repeat this cart", as the cart page does it: the cart is saved, repeated and processed again. */
    protected function repeat(OrderInterface $order, SubscriptionFrequencyInterface $frequency): void
    {
        $this->entityManager()->persist($order);
        $this->entityManager()->flush();

        /** @var CartRepeaterInterface $cartRepeater */
        $cartRepeater = self::getContainer()->get(CartRepeaterInterface::class);
        $cartRepeater->repeat($order, $frequency);
        /** @var OrderProcessorInterface $orderProcessor */
        $orderProcessor = self::getContainer()->get('sylius.order_processing.order_processor');
        $orderProcessor->process($order);
        $this->entityManager()->flush();
    }

    /** @return array<string, SubscriptionInterface> by the plan or frequency codes of their items, in order, joined by "+" */
    protected function subscriptionsByPlan(): array
    {
        $subscriptions = [];
        foreach ($this->storedSubscriptions() as $subscription) {
            $codes = [];
            foreach ($subscription->getItems() as $item) {
                $codes[] = (string) $item->getTerms()?->getCode();
            }
            $key = implode('+', $codes);
            self::assertArrayNotHasKey($key, $subscriptions, \sprintf('Two subscriptions on the "%s" plans.', $key));
            $subscriptions[$key] = $subscription;
        }
        ksort($subscriptions);

        return $subscriptions;
    }

    /** The subscription's only item. */
    protected function onlyItemOf(SubscriptionInterface $subscription): SubscriptionItemInterface
    {
        self::assertCount(1, $subscription->getItems());
        $item = $subscription->getItems()->first();
        self::assertInstanceOf(SubscriptionItemInterface::class, $item);

        return $item;
    }

    /** @return list<SubscriptionInterface> as stored, in the order they were started */
    protected function storedSubscriptions(): array
    {
        /** @var RepositoryInterface<SubscriptionInterface> $repository */
        $repository = self::getContainer()->get('jpm_martin_sylius_subscription.repository.subscription');

        // Without an order, a database returns rows as it finds them: an updated row may come last.
        $subscriptions = [];
        foreach ($repository->findBy([], ['id' => 'ASC']) as $subscription) {
            self::assertInstanceOf(SubscriptionInterface::class, $subscription);
            $this->entityManager()->refresh($subscription);
            $subscriptions[] = $subscription;
        }

        return $subscriptions;
    }

    /** @return list<SubscriptionCycleInterface> as stored, by number */
    protected function storedCycles(SubscriptionInterface $subscription): array
    {
        /** @var RepositoryInterface<SubscriptionCycleInterface> $repository */
        $repository = self::getContainer()->get('jpm_martin_sylius_subscription.repository.subscription_cycle');

        $cycles = [];
        foreach ($repository->findBy(['subscription' => $subscription], ['number' => 'ASC']) as $cycle) {
            self::assertInstanceOf(SubscriptionCycleInterface::class, $cycle);
            $this->entityManager()->refresh($cycle);
            $cycles[] = $cycle;
        }

        return $cycles;
    }

    protected function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    private function address(): AddressInterface
    {
        /** @var FactoryInterface<AddressInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.address');
        $address = $factory->createNew();
        $address->setFirstName('John');
        $address->setLastName('Doe');
        $address->setStreet('Frost Alley');
        $address->setCity('Ankh Morpork');
        $address->setPostcode('90210');
        $address->setCountryCode('US');

        return $address;
    }

    protected function variantOfANewProduct(string $name, int $price): ProductVariantInterface
    {
        /** @var ProductContext $products */
        $products = self::getContainer()->get('sylius.behat.context.setup.product');
        $products->storeHasAProductPricedAt($name, $price);

        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = self::getContainer()->get('sylius.behat.shared_storage');
        /** @var ProductInterface $product */
        $product = $sharedStorage->get('product');
        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariantInterface::class, $variant);

        return $variant;
    }

    protected function plan(ProductVariantInterface $variant, string $code, int $intervalCount, SubscriptionIntervalUnit $unit, int $discount): SubscriptionPlanInterface
    {
        /** @var SubscriptionPlanFactoryInterface $factory */
        $factory = self::getContainer()->get('jpm_martin_sylius_subscription.factory.subscription_plan');
        $plan = $factory->createForVariant($variant);
        $plan->setCode($code);
        $plan->setName($code);
        $plan->setIntervalCount($intervalCount);
        $plan->setIntervalUnit($unit);
        $plan->setDiscountPercentage($discount);
        $this->entityManager()->persist($plan);

        return $plan;
    }
}
