<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Payment;

use JpmMartin\SyliusSubscriptionPlugin\Cycle\CycleFailureHandlerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionPaymentMethodChanged;
use JpmMartin\SyliusSubscriptionPlugin\Payment\RenewalPaymentLinkGeneratorInterface;
use Sylius\Behat\Context\Setup\AdminUserContext;
use Sylius\Behat\Context\Setup\PaymentContext;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Bundle\PaymentBundle\Announcer\PaymentRequestAnnouncerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Factory\PaymentRequestFactoryInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Event\EventCollector;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

/**
 * A monthly Coffee subscription activated on 1 January and paid with "Card on file", which the plugin
 * charges through the test gateway. Its renewal of 1 February is declined, with a retry on 2 February.
 * The channel also offers "Other card", which the plugin charges the same way, and "Bank transfer",
 * which it cannot charge without the customer.
 */
final class CustomerPaymentOfRenewalsTest extends LifecycleTestCase
{
    private PaymentMethodInterface $otherCard;

    /** A one-time order of Coffee, placed on 1 January and not paid. */
    private int $oneTimeOrderId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->otherCard = $this->otherCard();
        /** @var PaymentContext $payment */
        $payment = self::getContainer()->get('sylius.behat.context.setup.payment');
        $payment->storeAllowsPaying('Bank transfer');

        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedCoffeeOrder());
        $oneTimeOrder = $this->cart();
        $this->addLine($oneTimeOrder, $this->coffee, 1, null);
        $this->place($oneTimeOrder);
        $this->oneTimeOrderId = (int) $oneTimeOrder->getId();

        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $this->cycle(2)->getState());
    }

    public function testTheRenewalOrdersPaymentOffersOnlyTheMethodsThePluginCanChargeAndAnotherOrdersAllOfThem(): void
    {
        $methods = $this->offeredMethods($this->pendingPaymentOf($this->renewalOrder(2)));
        sort($methods);
        self::assertSame(['Card_on_file', 'Other_card'], $methods);

        $order = $this->entityManager()->find(OrderInterface::class, $this->oneTimeOrderId);
        self::assertInstanceOf(OrderInterface::class, $order);
        $methods = $this->offeredMethods($this->pendingPaymentOf($order));
        sort($methods);
        self::assertSame(['Bank_transfer', 'Card_on_file', 'Other_card'], $methods);
    }

    public function testTheCustomerPayingTheDeclinedRenewalChargesItsCycleAndItIsNotRetried(): void
    {
        $this->itIsNow('2027-02-01 12:00');
        $this->customerPays($this->renewalOrder(2));

        $cycle = $this->cycle(2);
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $cycle->getState());
        self::assertSame([['charge', 'declined'], ['customer', 'approved']], $this->attemptsOf($cycle));
        self::assertSame('Card_on_file', $this->subscription()->getPaymentMethod()?->getCode());
        self::assertCount(0, $this->collector()->events(SubscriptionPaymentMethodChanged::class));

        $requestsBefore = \count($this->scriptedGateway()->requests());
        $this->itIsNow('2027-02-02 09:00');
        $this->runTheCycleCommand();
        self::assertCount($requestsBefore, $this->scriptedGateway()->requests(), 'The retry of 2 February is not made.');
        self::assertSame([['charge', 'declined'], ['customer', 'approved']], $this->attemptsOf($this->cycle(2)));
        $next = $this->cycle(3);
        self::assertSame(SubscriptionCycleInterface::STATE_SCHEDULED, $next->getState());
        self::assertSame('2027-03-01 09:00', $next->getScheduledAt()?->format('Y-m-d H:i'));
    }

    public function testPayingWithAnotherMethodThePluginCanChargeMovesTheSubscriptionToIt(): void
    {
        $otherCard = $this->entityManager()->find(PaymentMethodInterface::class, $this->otherCard->getId());
        self::assertInstanceOf(PaymentMethodInterface::class, $otherCard);
        $this->customerPays($this->renewalOrder(2), $otherCard);

        $subscription = $this->subscription();
        self::assertSame('Other_card', $subscription->getPaymentMethod()?->getCode());
        self::assertEquals(
            [new SubscriptionPaymentMethodChanged((int) $subscription->getId(), 'Other_card')],
            $this->collector()->events(SubscriptionPaymentMethodChanged::class),
        );

        $this->itIsNow('2027-03-01 09:00');
        $this->runTheCycleCommand();
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $this->cycle(3)->getState());
        self::assertSame('Other_card', $this->renewalOrder(3)->getLastPayment()?->getMethod()?->getCode());
    }

    public function testPayingWithAMethodThePluginCannotChargeKeepsTheSubscriptionsMethod(): void
    {
        $bankTransfer = $this->entityManager()->getRepository(PaymentMethodInterface::class)->findOneBy(['code' => 'Bank_transfer']);
        self::assertInstanceOf(PaymentMethodInterface::class, $bankTransfer);
        $payment = $this->pendingPaymentOf($this->renewalOrder(2));
        $payment->setMethod($bankTransfer);
        $this->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
        $this->entityManager()->flush();

        self::assertSame([['charge', 'declined'], ['customer', 'approved']], $this->attemptsOf($this->cycle(2)));
        self::assertSame('Card_on_file', $this->subscription()->getPaymentMethod()?->getCode());
        self::assertCount(0, $this->collector()->events(SubscriptionPaymentMethodChanged::class));
    }

    public function testARetryWaitsForAPaymentTheCustomerIsMakingAndIsNotMadeOnceTheyPay(): void
    {
        $this->itIsNow('2027-02-02 08:45');
        $paymentRequest = $this->customerStartsPaying($this->renewalOrder(2), null, '2027-02-02 08:45:00');
        $requestsBefore = \count($this->scriptedGateway()->requests());

        $this->itIsNow('2027-02-02 09:00');
        $this->runTheCycleCommand();

        self::assertCount($requestsBefore, $this->scriptedGateway()->requests(), 'The retry does not charge while the customer pays.');
        $cycle = $this->cycle(2);
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $cycle->getState());
        self::assertSame('2027-02-02 09:00', $cycle->getNextAttemptAt()?->format('Y-m-d H:i'), 'The retry is left for the next run.');
        self::assertSame([['charge', 'declined']], $this->attemptsOf($cycle));

        $this->itIsNow('2027-02-02 09:05');
        $this->customerFinishesPaying($paymentRequest);
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $this->cycle(2)->getState());

        $this->itIsNow('2027-02-02 10:00');
        $this->runTheCycleCommand();
        self::assertSame([['charge', 'declined'], ['customer', 'approved']], $this->attemptsOf($this->cycle(2)));
    }

    public function testAPaymentTheCustomerLeftUntouchedLongerThanTheWaitNoLongerHoldsTheRetryBack(): void
    {
        $this->itIsNow('2027-02-02 07:55');
        $this->customerStartsPaying($this->renewalOrder(2), null, '2027-02-02 07:55:00');

        $this->itIsNow('2027-02-02 09:00');
        $this->runTheCycleCommand();

        $cycle = $this->cycle(2);
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $cycle->getState(), 'An hour and five minutes without news: the payment was given up on.');
        self::assertSame([['charge', 'declined'], ['charge', 'approved']], $this->attemptsOf($cycle));
    }

    public function testACustomerPayingARenewalThePluginCouldNotAttemptIsRecordedAsTheirs(): void
    {
        $this->customerPays($this->renewalOrder(2));
        $bankTransfer = $this->entityManager()->getRepository(PaymentMethodInterface::class)->findOneBy(['code' => 'Bank_transfer']);
        self::assertInstanceOf(PaymentMethodInterface::class, $bankTransfer);
        $subscription = $this->subscription();
        $subscription->setPaymentMethod($bankTransfer);
        $this->entityManager()->flush();

        $this->itIsNow('2027-03-01 09:00');
        $this->runTheCycleCommand();
        self::assertSame([['charge', 'not_attempted']], $this->attemptsOf($this->cycle(3)));

        $cardOnFile = $this->entityManager()->getRepository(PaymentMethodInterface::class)->findOneBy(['code' => 'Card_on_file']);
        self::assertInstanceOf(PaymentMethodInterface::class, $cardOnFile);
        $this->customerPays($this->renewalOrder(3), $cardOnFile);

        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $this->cycle(3)->getState());
        self::assertSame([['charge', 'not_attempted'], ['customer', 'approved']], $this->attemptsOf($this->cycle(3)));
        self::assertSame('Card_on_file', $this->subscription()->getPaymentMethod()?->getCode());
    }

    public function testThePluginsOwnRetryIsNotRecordedAsTheCustomers(): void
    {
        $this->itIsNow('2027-02-02 09:00');
        $this->runTheCycleCommand();

        $cycle = $this->cycle(2);
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $cycle->getState());
        self::assertSame([['charge', 'declined'], ['charge', 'approved']], $this->attemptsOf($cycle));
        self::assertSame([], $this->attemptsOf($this->cycle(1)), 'The initial order is no customer payment of a renewal.');
    }

    public function testAPaymentAnAdministratorMarksCompleteIsNotRecordedAsTheCustomers(): void
    {
        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = self::getContainer()->get('sylius.behat.shared_storage');
        /** @var AdminUserContext $admins */
        $admins = self::getContainer()->get('sylius.behat.context.setup.admin_user');
        $admins->thereIsAnAdministratorIdentifiedBy('admin@example.com');
        $admin = $sharedStorage->get('administrator');
        self::assertInstanceOf(AdminUserInterface::class, $admin);
        /** @var TokenStorageInterface $tokenStorage */
        $tokenStorage = self::getContainer()->get('security.token_storage');
        $tokenStorage->setToken(new UsernamePasswordToken($admin, 'admin', $admin->getRoles()));

        $payment = $this->pendingPaymentOf($this->renewalOrder(2));
        $this->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
        $this->entityManager()->flush();
        $tokenStorage->setToken(null);

        $cycle = $this->cycle(2);
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $cycle->getState());
        self::assertSame([['charge', 'declined']], $this->attemptsOf($cycle));
    }

    public function testTheLinkOfADeclinedRenewalIsTheAbsoluteAddressOfItsOrdersPaymentPage(): void
    {
        $tokenValue = (string) $this->renewalOrder(2)->getTokenValue();
        self::assertNotSame('', $tokenValue);
        $channel = $this->entityManager()->find(ChannelInterface::class, $this->channel->getId());
        self::assertInstanceOf(ChannelInterface::class, $channel);

        $channel->setHostname(null);
        $this->entityManager()->flush();
        self::assertSame('http://localhost/en_US/order/' . $tokenValue, $this->linkGenerator()->generate($this->cycle(2)), 'The router\'s host, without one on the channel.');

        $channel->setHostname('shop.example.com');
        $this->entityManager()->flush();
        // As Sylius's own links: https, unless the store asks for unsecured ones, as the test store does.
        $scheme = true === self::getContainer()->getParameter('sylius.unsecured_urls') ? 'http' : 'https';
        self::assertSame($scheme . '://shop.example.com/en_US/order/' . $tokenValue, $this->linkGenerator()->generate($this->cycle(2)));
    }

    public function testThereIsNoLinkForARenewalPaidOrWithoutAnOrderYet(): void
    {
        self::assertNull($this->linkGenerator()->generate($this->cycle(1)), 'The initial order was paid.');

        $this->customerPays($this->renewalOrder(2));
        self::assertNull($this->linkGenerator()->generate($this->cycle(2)), 'Paid.');
        self::assertSame(SubscriptionCycleInterface::STATE_SCHEDULED, $this->cycle(3)->getState());
        self::assertNull($this->linkGenerator()->generate($this->cycle(3)), 'No order yet.');
    }

    public function testThereIsNoLinkWhileARetrysOutcomeIsUnknown(): void
    {
        $this->scriptedGateway()->willAnswer(ScriptedGateway::NO_ANSWER);
        $this->itIsNow('2027-02-02 09:00');
        $this->runTheCycleCommand();

        $cycle = $this->cycle(2);
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $cycle->getState());
        self::assertSame([['charge', 'declined'], ['charge', 'unknown']], $this->attemptsOf($cycle));
        self::assertNull($this->linkGenerator()->generate($cycle), 'The retry may have been charged.');
    }

    public function testThereIsNoLinkForAFailedRenewal(): void
    {
        /** @var CycleFailureHandlerInterface $failureHandler */
        $failureHandler = self::getContainer()->get('jpm_martin_sylius_subscription.cycle.failure_handler');
        $failureHandler->fail($this->cycle(2), 'Insufficient funds.');
        $this->entityManager()->flush();

        $cycle = $this->cycle(2);
        self::assertSame(SubscriptionCycleInterface::STATE_FAILED, $cycle->getState());
        self::assertNull($this->linkGenerator()->generate($cycle));
    }

    private function linkGenerator(): RenewalPaymentLinkGeneratorInterface
    {
        $generator = self::getContainer()->get(RenewalPaymentLinkGeneratorInterface::class);
        self::assertInstanceOf(RenewalPaymentLinkGeneratorInterface::class, $generator);

        return $generator;
    }

    /** As the store's order payment page does: a payment request of the order's pending payment, sent to its gateway. */
    private function customerPays(OrderInterface $order, ?PaymentMethodInterface $method = null): void
    {
        $this->customerFinishesPaying($this->customerStartsPaying($order, $method));
    }

    /**
     * The customer on the gateway's page: the payment request exists, and its gateway has not answered.
     * Sylius dates it by the system's clock, so the test dates it by the test's.
     */
    private function customerStartsPaying(OrderInterface $order, ?PaymentMethodInterface $method = null, ?string $at = null): PaymentRequestInterface
    {
        $payment = $this->pendingPaymentOf($order);
        $method ??= $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $payment->setMethod($method);

        /** @var PaymentRequestFactoryInterface<PaymentRequestInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.payment_request');
        $paymentRequest = $factory->create($payment, $method);
        $paymentRequest->setAction(PaymentRequestInterface::ACTION_CAPTURE);
        /** @var RepositoryInterface<PaymentRequestInterface> $repository */
        $repository = self::getContainer()->get('sylius.repository.payment_request');
        $repository->add($paymentRequest);

        if (null !== $at) {
            $this->entityManager()->getConnection()->executeStatement(
                'UPDATE sylius_payment_request SET created_at = :at, updated_at = :at WHERE hash = :hash',
                ['at' => $at, 'hash' => (string) $paymentRequest->getId()],
            );
            $this->entityManager()->refresh($paymentRequest);
        }

        return $paymentRequest;
    }

    /** The gateway answers the customer's payment request: the test gateway approves it. */
    private function customerFinishesPaying(PaymentRequestInterface $paymentRequest): void
    {
        $paymentRequest = $this->entityManager()->find(PaymentRequestInterface::class, $paymentRequest->getId());
        self::assertInstanceOf(PaymentRequestInterface::class, $paymentRequest);
        /** @var PaymentRequestAnnouncerInterface $announcer */
        $announcer = self::getContainer()->get(PaymentRequestAnnouncerInterface::class);
        $announcer->dispatchPaymentRequestCommand($paymentRequest);
        $this->entityManager()->flush();
    }

    /** "Other card", which the plugin charges through the test gateway too. */
    private function otherCard(): PaymentMethodInterface
    {
        /** @var PaymentContext $payment */
        $payment = self::getContainer()->get('sylius.behat.context.setup.payment');
        $payment->storeAllowsPaying('Other card');
        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = self::getContainer()->get('sylius.behat.shared_storage');
        $otherCard = $sharedStorage->get('payment_method');
        self::assertInstanceOf(PaymentMethodInterface::class, $otherCard);
        $gatewayConfig = $otherCard->getGatewayConfig();
        self::assertInstanceOf(GatewayConfigInterface::class, $gatewayConfig);
        $gatewayConfig->setFactoryName('scripted');
        $gatewayConfig->setUsePayum(false);
        $this->entityManager()->flush();

        return $otherCard;
    }

    /** @return list<array{string, string}> the type and outcome of each attempt, in order */
    private function attemptsOf(SubscriptionCycleInterface $cycle): array
    {
        $attempts = [];
        foreach ($cycle->getAttempts() as $attempt) {
            $attempts[] = [$attempt->getType(), $attempt->getOutcome()];
        }

        return $attempts;
    }

    private function collector(): EventCollector
    {
        $collector = self::getContainer()->get('jpm_martin_sylius_subscription.test.event_collector');
        self::assertInstanceOf(EventCollector::class, $collector);

        return $collector;
    }

    /** @return list<string> the codes of the methods the order payment page would offer */
    private function offeredMethods(PaymentInterface $payment): array
    {
        /** @var PaymentMethodsResolverInterface $resolver */
        $resolver = self::getContainer()->get('sylius.resolver.payment_methods');

        return array_values(array_map(
            static fn ($method): string => (string) $method->getCode(),
            $resolver->getSupportedMethods($payment),
        ));
    }

    private function pendingPaymentOf(OrderInterface $order): PaymentInterface
    {
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertInstanceOf(PaymentInterface::class, $payment);

        return $payment;
    }

    private function subscription(): SubscriptionInterface
    {
        return $this->subscriptionsByPlan()['COFFEE_MONTHLY'];
    }

    private function cycle(int $number): SubscriptionCycleInterface
    {
        foreach ($this->storedCycles($this->subscription()) as $cycle) {
            if ($number === $cycle->getNumber()) {
                return $cycle;
            }
        }

        self::fail(\sprintf('The subscription has no cycle %d.', $number));
    }

    private function renewalOrder(int $number): OrderInterface
    {
        $order = $this->cycle($number)->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);
        $this->entityManager()->refresh($order);

        return $order;
    }
}
