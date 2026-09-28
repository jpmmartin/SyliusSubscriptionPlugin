<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Functional\Shop;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Behat\Context\Hook\CalendarContext as CalendarHookContext;
use Sylius\Behat\Context\Hook\DoctrineORMContext;
use Sylius\Behat\Context\Setup\CalendarContext;
use Sylius\Behat\Context\Setup\ChannelContext;
use Sylius\Behat\Context\Setup\PaymentContext;
use Sylius\Behat\Context\Setup\ProductContext;
use Sylius\Behat\Context\Setup\ShippingContext;
use Sylius\Behat\Context\Setup\UserContext;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup\SubscriptionContext;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup\SubscriptionPlanContext;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

/**
 * A cart whose only line is a free trial of Coffee, shipped for free, so it costs nothing: it keeps a
 * payment of 0, goes through Sylius's own payment step and, once completed, has that payment
 * authorized by the gateway through a payment request, so the gateway can keep the card.
 */
final class StartingAFreeTrialTest extends WebTestCase
{
    private const LOCALE = 'en_US';

    private KernelBrowser $client;

    private OrderInterface $cart;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();

        /** @var DoctrineORMContext $database */
        $database = $container->get('sylius.behat.context.hook.doctrine_orm');
        $database->purgeDatabase();
        /** @var CalendarContext $calendar */
        $calendar = $container->get('sylius.behat.context.setup.calendar');
        $calendar->itIsNow('2027-03-01 09:00');

        /** @var ChannelContext $channels */
        $channels = $container->get('sylius.behat.context.setup.channel');
        $channels->storeOperatesOnASingleChannelInUnitedStates();
        /** @var ProductContext $products */
        $products = $container->get('sylius.behat.context.setup.product');
        $products->storeHasAProductPricedAt('Coffee', 2000);
        /** @var ShippingContext $shipping */
        $shipping = $container->get('sylius.behat.context.setup.shipping');
        $shipping->theStoreShipsEverywhereWith('Free');
        /** @var PaymentContext $payment */
        $payment = $container->get('sylius.behat.context.setup.payment');
        $payment->storeAllowsPaying('Card on file');
        /** @var SubscriptionContext $subscriptions */
        $subscriptions = $container->get('jpm_martin_sylius_subscription.behat.context.setup.subscription');
        $subscriptions->thePaymentMethodChargesRenewalsThroughTheTestGateway('Card on file');

        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = $container->get('sylius.behat.shared_storage');
        /** @var ProductInterface $product */
        $product = $sharedStorage->get('product');
        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariantInterface::class, $variant);
        /** @var SubscriptionPlanContext $plans */
        $plans = $container->get('jpm_martin_sylius_subscription.behat.context.setup.subscription_plan');
        $plans->theVariantOffersASubscriptionPlan($variant, 'COFFEE_MONTHLY', '1', 'month', '10');
        $plan = $sharedStorage->get('subscription_plan');
        self::assertInstanceOf(SubscriptionPlanInterface::class, $plan);
        $plan->setTrialDays(14);

        /** @var UserContext $users */
        $users = $container->get('sylius.behat.context.setup.user');
        $users->thereIsUserIdentifiedBy('me@example.com');
        $me = $sharedStorage->get('user');
        self::assertInstanceOf(ShopUserInterface::class, $me);
        $customer = $me->getCustomer();
        self::assertInstanceOf(CustomerInterface::class, $customer);
        /** @var ChannelInterface $channel */
        $channel = $sharedStorage->get('channel');
        /** @var ShippingMethodInterface $shippingMethod */
        $shippingMethod = $sharedStorage->get('shipping_method');

        $this->cart = $this->cartOf($customer, $channel, $variant, $plan);
        $this->addressAndShip($this->cart, $shippingMethod);

        $this->client->loginUser($me, 'shop');
        // One container for the whole test, so the gateway it asks is the one the requests used.
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        /** @var CalendarHookContext $calendar */
        $calendar = self::getContainer()->get('sylius.behat.context.hook.calendar');
        $calendar->deleteTemporaryDate();

        parent::tearDown();
    }

    public function testACartOfAFreeTrialKeepsAPaymentOfZeroAndGoesThroughThePaymentStep(): void
    {
        self::assertSame(0, $this->cart->getTotal());
        self::assertCount(1, $this->cart->getPayments(), 'Sylius would drop the payments of an order of 0.');
        self::assertSame(0, $this->cart->getLastPayment()?->getAmount());
        self::assertSame(OrderCheckoutStates::STATE_SHIPPING_SELECTED, $this->cart->getCheckoutState(), 'The payment step was not skipped.');

        $crawler = $this->client->request('GET', \sprintf('/%s/checkout/select-payment', self::LOCALE));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $crawler->filter('form[name="sylius_shop_checkout_select_payment"]')->form();
        $form['sylius_shop_checkout_select_payment[payments][0][method]']->setValue('Card_on_file');
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertStringEndsWith('/checkout/complete', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testCompletingTheOrderHasItsPaymentOfZeroAuthorizedByTheGateway(): void
    {
        $crawler = $this->client->request('GET', \sprintf('/%s/checkout/select-payment', self::LOCALE));
        $form = $crawler->filter('form[name="sylius_shop_checkout_select_payment"]')->form();
        $form['sylius_shop_checkout_select_payment[payments][0][method]']->setValue('Card_on_file');
        $this->client->submit($form);

        $crawler = $this->client->request('GET', \sprintf('/%s/checkout/complete', self::LOCALE));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $consent = $crawler->filter('input[name$="[subscriptionConsent]"]');
        self::assertCount(1, $consent, 'The complete step asks for the consent to recurring charges.');
        $form = $consent->closest('form')?->form();
        self::assertNotNull($form);
        $consentField = $form[(string) $consent->attr('name')];
        self::assertInstanceOf(ChoiceFormField::class, $consentField);
        $consentField->tick();
        $this->client->followRedirects();
        $this->client->submit($form);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertSame([PaymentInterface::STATE_AUTHORIZED], $this->paymentStates());
        self::assertSame(['authorize', 'status'], $this->scriptedGateway()->requests(), 'Authorized, then asked for its status after the customer came back.');
        $order = $this->storedCart();
        self::assertSame(OrderCheckoutStates::STATE_COMPLETED, $order->getCheckoutState());
        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $order->getPaymentState());
    }

    private function cartOf(CustomerInterface $customer, ChannelInterface $channel, ProductVariantInterface $variant, SubscriptionPlanInterface $plan): OrderInterface
    {
        $container = self::getContainer();
        /** @var FactoryInterface<OrderInterface> $orderFactory */
        $orderFactory = $container->get('sylius.factory.order');
        $cart = $orderFactory->createNew();
        $cart->setChannel($channel);
        $cart->setCurrencyCode('USD');
        $cart->setLocaleCode(self::LOCALE);
        $cart->setCustomer($customer);
        $cart->setCustomerWithAuthorization($customer);

        /** @var FactoryInterface<OrderItemInterface> $itemFactory */
        $itemFactory = $container->get('sylius.factory.order_item');
        $line = $itemFactory->createNew();
        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $line);
        $line->setVariant($variant);
        $line->setSubscriptionPlan($plan);
        /** @var OrderItemQuantityModifierInterface $quantityModifier */
        $quantityModifier = $container->get('sylius.modifier.order_item_quantity');
        $quantityModifier->modify($line, 1);
        /** @var OrderModifierInterface $orderModifier */
        $orderModifier = $container->get('sylius.modifier.order');
        $orderModifier->addToOrder($cart, $line);

        $this->entityManager()->persist($cart);
        $this->entityManager()->flush();

        return $cart;
    }

    private function addressAndShip(OrderInterface $cart, ShippingMethodInterface $shippingMethod): void
    {
        /** @var FactoryInterface<AddressInterface> $addressFactory */
        $addressFactory = self::getContainer()->get('sylius.factory.address');
        foreach (['setShippingAddress', 'setBillingAddress'] as $setter) {
            $address = $addressFactory->createNew();
            $address->setFirstName('Jon');
            $address->setLastName('Snow');
            $address->setStreet('Frost Alley');
            $address->setCity('Ankh Morpork');
            $address->setPostcode('90210');
            $address->setCountryCode('US');
            $cart->{$setter}($address);
        }

        /** @var StateMachineInterface $stateMachine */
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');
        $stateMachine->apply($cart, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_ADDRESS);
        foreach ($cart->getShipments() as $shipment) {
            $shipment->setMethod($shippingMethod);
        }
        $stateMachine->apply($cart, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING);
        $this->entityManager()->flush();
    }

    /** @return list<string> */
    private function paymentStates(): array
    {
        return array_values(array_map(static fn (PaymentInterface $payment): string => $payment->getState(), $this->storedCart()->getPayments()->toArray()));
    }

    private function storedCart(): OrderInterface
    {
        $this->entityManager()->clear();
        $order = $this->entityManager()->find($this->cart::class, $this->cart->getId());
        self::assertInstanceOf(OrderInterface::class, $order);

        return $order;
    }

    private function scriptedGateway(): ScriptedGateway
    {
        $gateway = self::getContainer()->get('jpm_martin_sylius_subscription.test.scripted_gateway');
        self::assertInstanceOf(ScriptedGateway::class, $gateway);

        return $gateway;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }
}
