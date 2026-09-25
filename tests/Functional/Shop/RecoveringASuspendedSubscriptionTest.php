<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Functional\Shop;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
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
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Domain\ProcessingRenewalsContext;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup\SubscriptionContext;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup\SubscriptionPlanContext;

/**
 * The link that recovers a subscription suspended after its renewals of February, March and April
 * failed: only its customer, signed in, reaches it, and it leads them to the payment page of the order
 * that recovers it. The store is set up with the same Behat setup services the scenarios use.
 */
final class RecoveringASuspendedSubscriptionTest extends WebTestCase
{
    private const LOCALE = 'en_US';

    private KernelBrowser $client;

    private int $subscriptionId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();

        /** @var DoctrineORMContext $database */
        $database = $container->get('sylius.behat.context.hook.doctrine_orm');
        $database->purgeDatabase();
        /** @var CalendarContext $calendar */
        $calendar = $container->get('sylius.behat.context.setup.calendar');
        $calendar->itIsNow('2027-01-01 09:00');

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

        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = $container->get('sylius.behat.shared_storage');
        /** @var ProductInterface $product */
        $product = $sharedStorage->get('product');
        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariantInterface::class, $variant);
        /** @var SubscriptionPlanContext $plans */
        $plans = $container->get('jpm_martin_sylius_subscription.behat.context.setup.subscription_plan');
        $plans->theVariantOffersASubscriptionPlan($variant, 'COFFEE_MONTHLY', '1', 'month', '10');

        /** @var UserContext $users */
        $users = $container->get('sylius.behat.context.setup.user');
        $users->thereIsUserIdentifiedBy('me@example.com');

        /** @var SubscriptionContext $subscriptions */
        $subscriptions = $container->get('jpm_martin_sylius_subscription.behat.context.setup.subscription');
        $subscriptions->thePaymentMethodChargesRenewalsThroughTheTestGateway('Card on file');
        $subscriptions->iSubscribedTo('Coffee', 'COFFEE_MONTHLY');
        $subscription = $sharedStorage->get('subscription');
        self::assertInstanceOf(SubscriptionInterface::class, $subscription);
        $this->subscriptionId = (int) $subscription->getId();

        /** @var ProcessingRenewalsContext $renewals */
        $renewals = $container->get('jpm_martin_sylius_subscription.behat.context.domain.processing_renewals');
        $renewals->theNextRenewalsOfMySubscriptionFailed(3);
        self::assertSame(SubscriptionInterface::STATE_SUSPENDED, $this->subscription()->getState());
        $calendar->itIsNow('2027-04-10 09:00');
    }

    protected function tearDown(): void
    {
        /** @var CalendarHookContext $calendar */
        $calendar = self::getContainer()->get('sylius.behat.context.hook.calendar');
        $calendar->deleteTemporaryDate();

        parent::tearDown();
    }

    public function testWithoutSigningInTheCustomerIsAskedToSignIn(): void
    {
        $this->client->request('GET', $this->url());

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame(SubscriptionCycleInterface::STATE_FAILED, $this->lastCycle()->getState());
    }

    public function testAnotherCustomerDoesNotFindItAndChangesNothing(): void
    {
        $this->signInAs('eve@example.com');

        $this->client->request('GET', $this->url());

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame(SubscriptionCycleInterface::STATE_FAILED, $this->lastCycle()->getState());
    }

    public function testItsCustomerStartsTheRecoveryAndIsLedToThePaymentPageOfItsOrder(): void
    {
        $this->signInAs('me@example.com');

        $this->client->request('GET', $this->url());

        $cycle = $this->lastCycle();
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $cycle->getState());
        $order = $cycle->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            \sprintf('/%s/order/%s', self::LOCALE, (string) $order->getTokenValue()),
            $this->client->getResponse()->headers->get('Location'),
        );

        $this->client->request('GET', $this->url());
        self::assertSame(
            \sprintf('/%s/order/%s', self::LOCALE, (string) $order->getTokenValue()),
            $this->client->getResponse()->headers->get('Location'),
            'Following the link again leads to the same order.',
        );
    }

    public function testASubscriptionItsCustomerCannotRecoverLeadsBackToItsPageAndChangesNothing(): void
    {
        // An administrator reactivates it and suspends it again: the suspension is theirs.
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');
        $subscription = $this->subscription();
        $stateMachine->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_REACTIVATE);
        $stateMachine->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_SUSPEND);
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();
        $ordersBefore = $this->countOrders();
        $this->signInAs('me@example.com');

        $this->client->request('GET', $this->url());

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            \sprintf('/%s/account/subscriptions/%d', self::LOCALE, $this->subscriptionId),
            $this->client->getResponse()->headers->get('Location'),
        );
        self::assertSame($ordersBefore, $this->countOrders());
        self::assertSame(SubscriptionInterface::STATE_SUSPENDED, $this->subscription()->getState());
        $this->client->followRedirect();
        self::assertStringContainsString('This subscription cannot be recovered now.', (string) $this->client->getResponse()->getContent());
    }

    private function countOrders(): int
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        return (int) $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM sylius_order');
    }

    private function signInAs(string $email): void
    {
        $container = self::getContainer();
        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = $container->get('sylius.behat.shared_storage');
        if ('me@example.com' !== $email) {
            /** @var UserContext $users */
            $users = $container->get('sylius.behat.context.setup.user');
            $users->thereIsUserIdentifiedBy($email);
        }
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get('doctrine.orm.entity_manager');
        $user = $entityManager->getRepository(ShopUserInterface::class)->findOneBy(['username' => $email]);
        self::assertInstanceOf(ShopUserInterface::class, $user);
        $this->client->loginUser($user, 'shop');
    }

    private function url(): string
    {
        return \sprintf('/%s/account/subscriptions/%d/recover', self::LOCALE, $this->subscriptionId);
    }

    private function subscription(): SubscriptionInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $entityManager->clear();
        $subscription = $entityManager->find(SubscriptionInterface::class, $this->subscriptionId);
        self::assertInstanceOf(SubscriptionInterface::class, $subscription);

        return $subscription;
    }

    private function lastCycle(): SubscriptionCycleInterface
    {
        $last = null;
        foreach ($this->subscription()->getCycles() as $cycle) {
            if (null === $last || $cycle->getNumber() > $last->getNumber()) {
                $last = $cycle;
            }
        }
        self::assertInstanceOf(SubscriptionCycleInterface::class, $last);

        return $last;
    }
}
