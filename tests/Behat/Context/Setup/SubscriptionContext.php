<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup;

use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusSubscriptionPlugin\Consent\SubscriptionConsentRecorderInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\CartRepeaterInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;
use Webmozart\Assert\Assert;

/**
 * A subscription comes from a real order: each line carries its plan, the checkout completes with the
 * consent accepted and the order is paid, so the plugin itself starts and activates the subscription
 * on the date the scenario says it is. Lines on the same interval make one subscription of several
 * items.
 */
final class SubscriptionContext implements Context
{
    /**
     * @param RepositoryInterface<SubscriptionPlanInterface> $planRepository
     * @param SubscriptionRepositoryInterface<SubscriptionInterface> $subscriptionRepository
     * @param FactoryInterface<OrderInterface> $orderFactory
     * @param FactoryInterface<OrderItemInterface> $orderItemFactory
     * @param FactoryInterface<AddressInterface> $addressFactory
     * @param FactoryInterface<CustomerInterface> $customerFactory
     * @param RepositoryInterface<CustomerInterface> $customerRepository
     * @param RepositoryInterface<SubscriptionFrequencyInterface> $frequencyRepository
     */
    public function __construct(
        private readonly RepositoryInterface $planRepository,
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly FactoryInterface $orderFactory,
        private readonly FactoryInterface $orderItemFactory,
        private readonly FactoryInterface $addressFactory,
        private readonly FactoryInterface $customerFactory,
        private readonly OrderItemQuantityModifierInterface $quantityModifier,
        private readonly OrderModifierInterface $orderModifier,
        private readonly StateMachineInterface $stateMachine,
        private readonly SubscriptionConsentRecorderInterface $consentRecorder,
        private readonly ObjectManager $entityManager,
        private readonly SharedStorageInterface $sharedStorage,
        private readonly RepositoryInterface $customerRepository,
        private readonly ScriptedGateway $scriptedGateway,
        private readonly RepositoryInterface $frequencyRepository,
        private readonly CartRepeaterInterface $cartRepeater,
        private readonly OrderProcessorInterface $orderProcessor,
    ) {
    }

    #[Given('/^the subscription of "([^"]+)" has been (suspended|cancelled|paused)$/')]
    public function theSubscriptionOfHasBeen(string $email, string $state): void
    {
        $this->bring($this->subscriptionOf($email), $state);
    }

    #[Given('my subscription has been paused')]
    public function mySubscriptionHasBeenPaused(): void
    {
        $subscription = $this->sharedStorage->get('subscription');
        Assert::isInstanceOf($subscription, SubscriptionInterface::class);

        $this->bring($subscription, 'paused');
    }

    private function bring(SubscriptionInterface $subscription, string $state): void
    {
        $transition = match ($state) {
            'suspended' => SubscriptionTransitions::TRANSITION_SUSPEND,
            'paused' => SubscriptionTransitions::TRANSITION_PAUSE,
            default => SubscriptionTransitions::TRANSITION_CANCEL,
        };

        $this->stateMachine->apply($subscription, SubscriptionTransitions::GRAPH, $transition);
        $this->entityManager->flush();
    }

    #[Given('/^the test gateway will decline the next charge with "([^"]+)"$/')]
    public function theTestGatewayWillDeclineTheNextCharge(string $reason): void
    {
        $this->theTestGatewayWillDeclineTheNextCharges(1, $reason);
    }

    #[Given('/^the test gateway will decline the next charge with "([^"]+)" and the code "([^"]+)"$/')]
    public function theTestGatewayWillDeclineTheNextChargeWithTheCode(string $reason, string $code): void
    {
        $this->scriptedGateway->willAnswer(ScriptedGateway::DECLINE, $reason, $code);
    }

    #[Given('/^the test gateway will decline the next (\d+) charges with "([^"]+)"$/')]
    public function theTestGatewayWillDeclineTheNextCharges(int $count, string $reason): void
    {
        for ($charge = 0; $charge < $count; ++$charge) {
            $this->scriptedGateway->willAnswer(ScriptedGateway::DECLINE, $reason);
        }
    }

    #[Given('/^I subscribed to "([^"]+)" on the "([^"]+)" plan$/')]
    public function iSubscribedTo(string $productName, string $planCode): void
    {
        $this->subscribe($this->myCustomer(), [[$productName, $planCode]]);
    }

    #[Given('/^I subscribed to "([^"]+)" on the "([^"]+)" plan and to "([^"]+)" on the "([^"]+)" plan$/')]
    public function iSubscribedToBoth(string $productName, string $planCode, string $otherProductName, string $otherPlanCode): void
    {
        $this->subscribe($this->myCustomer(), [[$productName, $planCode], [$otherProductName, $otherPlanCode]]);
    }

    /** The variant is put in the cart once and the cart repeated, as the cart page does; the store must let it be repeated. */
    #[Given('/^I repeated my cart of the ("[^"]+" variant) with the "([^"]+)" subscription frequency$/')]
    public function iRepeatedMyCartOf(ProductVariantInterface $variant, string $frequencyCode): void
    {
        $frequency = $this->frequencyRepository->findOneBy(['code' => $frequencyCode]);
        Assert::isInstanceOf($frequency, SubscriptionFrequencyInterface::class);

        $this->subscribe($this->myCustomer(), [], [$variant], $frequency);
    }

    #[Given('/^the customer "([^"]+)" subscribed to "([^"]+)" on the "([^"]+)" plan$/')]
    public function theCustomerSubscribedTo(string $email, string $productName, string $planCode): void
    {
        $this->subscribe($this->newCustomer($email), [[$productName, $planCode]]);
    }

    #[Given('/^the customer "([^"]+)" subscribed to "([^"]+)" on the "([^"]+)" plan and to "([^"]+)" on the "([^"]+)" plan$/')]
    public function theCustomerSubscribedToBoth(string $email, string $productName, string $planCode, string $otherProductName, string $otherPlanCode): void
    {
        $this->subscribe($this->newCustomer($email), [[$productName, $planCode], [$otherProductName, $otherPlanCode]]);
    }

    #[Given('/^the "([^"]+)" payment method charges renewals through the test gateway$/')]
    public function thePaymentMethodChargesRenewalsThroughTheTestGateway(string $paymentMethodName): void
    {
        $paymentMethod = $this->sharedStorage->get('payment_method');
        Assert::isInstanceOf($paymentMethod, PaymentMethodInterface::class);
        Assert::same($paymentMethod->getName(), $paymentMethodName);
        $gatewayConfig = $paymentMethod->getGatewayConfig();
        Assert::isInstanceOf($gatewayConfig, GatewayConfigInterface::class);

        $gatewayConfig->setFactoryName('scripted');
        $gatewayConfig->setUsePayum(false);
        $this->entityManager->flush();
    }

    private function myCustomer(): CustomerInterface
    {
        $user = $this->sharedStorage->get('user');
        Assert::isInstanceOf($user, ShopUserInterface::class);
        $customer = $user->getCustomer();
        Assert::isInstanceOf($customer, CustomerInterface::class);

        return $customer;
    }

    private function newCustomer(string $email): CustomerInterface
    {
        $customer = $this->customerFactory->createNew();
        $customer->setEmail($email);
        $customer->setFirstName('Ann');
        $customer->setLastName('Other');
        $this->entityManager->persist($customer);

        return $customer;
    }

    /**
     * @param list<array{string, string}> $lines a product's name and the code of its plan, for each line
     * @param list<ProductVariantInterface> $repeatedVariants variants bought once in a cart repeated with the frequency
     */
    private function subscribe(CustomerInterface $customer, array $lines, array $repeatedVariants = [], ?SubscriptionFrequencyInterface $frequency = null): void
    {
        $channel = $this->sharedStorage->get('channel');
        Assert::isInstanceOf($channel, ChannelInterface::class);

        $order = $this->orderFactory->createNew();
        $order->setChannel($channel);
        $order->setCurrencyCode($channel->getBaseCurrency()?->getCode());
        $order->setLocaleCode($channel->getDefaultLocale()?->getCode());
        $order->setCustomer($customer);
        $order->setShippingAddress($this->address($customer));
        $order->setBillingAddress($this->address($customer));

        foreach ($lines as [$productName, $planCode]) {
            $plan = $this->planRepository->findOneBy(['code' => $planCode]);
            Assert::isInstanceOf($plan, SubscriptionPlanInterface::class);
            $variant = $plan->getProductVariant();
            Assert::same($variant?->getProduct()?->getName(), $productName);

            $item = $this->orderItemFactory->createNew();
            Assert::isInstanceOf($item, SubscriptionPlanAwareInterface::class);
            $item->setVariant($variant);
            $item->setSubscriptionPlan($plan);
            $this->quantityModifier->modify($item, 1);
            $this->orderModifier->addToOrder($order, $item);
        }
        foreach ($repeatedVariants as $variant) {
            $item = $this->orderItemFactory->createNew();
            $item->setVariant($variant);
            $this->quantityModifier->modify($item, 1);
            $this->orderModifier->addToOrder($order, $item);
        }

        $this->entityManager->persist($order);
        $this->entityManager->flush();
        if (null !== $frequency) {
            $this->cartRepeater->repeat($order, $frequency);
            $this->orderProcessor->process($order);
            $this->entityManager->flush();
        }
        $this->consentRecorder->record($order);

        $this->applyCheckout($order, OrderCheckoutTransitions::TRANSITION_ADDRESS);
        if ($this->stateMachine->can($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING)) {
            $shippingMethod = $this->sharedStorage->get('shipping_method');
            Assert::isInstanceOf($shippingMethod, ShippingMethodInterface::class);
            foreach ($order->getShipments() as $shipment) {
                $shipment->setMethod($shippingMethod);
            }
            $this->applyCheckout($order, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING);
        }
        $paymentMethod = $this->sharedStorage->get('payment_method');
        Assert::isInstanceOf($paymentMethod, PaymentMethodInterface::class);
        $order->getLastPayment(PaymentInterface::STATE_CART)?->setMethod($paymentMethod);
        $this->applyCheckout($order, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT);
        $this->applyCheckout($order, OrderCheckoutTransitions::TRANSITION_COMPLETE);
        $this->entityManager->flush();

        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        Assert::notNull($payment);
        $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
        $this->entityManager->flush();

        $subscriptions = $this->subscriptionRepository->findByInitialOrder($order);
        Assert::count($subscriptions, 1);
        $this->sharedStorage->set('subscription', $subscriptions[0]);
    }

    private function subscriptionOf(string $email): SubscriptionInterface
    {
        $customer = $this->customerRepository->findOneBy(['email' => $email]);
        Assert::isInstanceOf($customer, CustomerInterface::class);
        $subscriptions = $this->subscriptionRepository->findByCustomer($customer);
        Assert::count($subscriptions, 1);

        return $subscriptions[0];
    }

    private function applyCheckout(OrderInterface $order, string $transition): void
    {
        $this->stateMachine->apply($order, OrderCheckoutTransitions::GRAPH, $transition);
    }

    private function address(CustomerInterface $customer): AddressInterface
    {
        $address = $this->addressFactory->createNew();
        $address->setFirstName($customer->getFirstName() ?? 'John');
        $address->setLastName($customer->getLastName() ?? 'Doe');
        $address->setStreet('Frost Alley');
        $address->setCity('Ankh Morpork');
        $address->setPostcode('90210');
        $address->setCountryCode('US');

        return $address;
    }
}
