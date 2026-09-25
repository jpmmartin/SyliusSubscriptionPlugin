<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Functional\Shop;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use Sylius\Behat\Context\Hook\CalendarContext as CalendarHookContext;
use Sylius\Behat\Context\Hook\DoctrineORMContext;
use Sylius\Behat\Context\Setup\CalendarContext;
use Sylius\Behat\Context\Setup\ChannelContext;
use Sylius\Behat\Context\Setup\PaymentContext;
use Sylius\Behat\Context\Setup\ProductContext;
use Sylius\Behat\Context\Setup\ShippingContext;
use Sylius\Behat\Context\Setup\UserContext;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup\SubscriptionContext;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup\SubscriptionPlanContext;

/**
 * A signed-in customer pausing, resuming or skipping the renewal of another customer's subscription by
 * its address: the account's routes look the subscription up among the customer's own, so they answer
 * as if it did not exist, before any token is checked, and change nothing.
 *
 * The store is set up with the same Behat setup services the scenarios use.
 */
final class ActingOnSomeoneElsesSubscriptionTest extends WebTestCase
{
    private const LOCALE = 'en_US';

    private KernelBrowser $client;

    private int $othersSubscriptionId;

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

        /** @var SubscriptionContext $subscriptions */
        $subscriptions = $container->get('jpm_martin_sylius_subscription.behat.context.setup.subscription');
        $subscriptions->thePaymentMethodChargesRenewalsThroughTheTestGateway('Card on file');
        $subscriptions->theCustomerSubscribedTo('ann@example.com', 'Coffee', 'COFFEE_MONTHLY');
        $othersSubscription = $sharedStorage->get('subscription');
        self::assertInstanceOf(SubscriptionInterface::class, $othersSubscription);
        $this->othersSubscriptionId = (int) $othersSubscription->getId();

        /** @var UserContext $users */
        $users = $container->get('sylius.behat.context.setup.user');
        $users->thereIsUserIdentifiedBy('me@example.com');
        $me = $sharedStorage->get('user');
        self::assertInstanceOf(ShopUserInterface::class, $me);
        $this->client->loginUser($me, 'shop');
    }

    protected function tearDown(): void
    {
        /** @var CalendarHookContext $calendar */
        $calendar = self::getContainer()->get('sylius.behat.context.hook.calendar');
        $calendar->deleteTemporaryDate();

        parent::tearDown();
    }

    public function testPausingSomeoneElsesSubscriptionIsNotFoundAndChangesNothing(): void
    {
        $this->client->request('PUT', $this->url('pause'));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertTheSubscriptionIsUntouched();
    }

    public function testResumingSomeoneElsesSubscriptionIsNotFound(): void
    {
        $this->client->request('PUT', $this->url('resume'));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertTheSubscriptionIsUntouched();
    }

    public function testSkippingTheRenewalOfSomeoneElsesSubscriptionIsNotFoundAndChangesNothing(): void
    {
        $this->client->request('POST', $this->url('skip-renewal'));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertTheSubscriptionIsUntouched();
    }

    /** The control: the same requests reach the customer's own subscription, and only stop at its missing token. */
    public function testTheSameRequestsFindTheCustomersOwnSubscription(): void
    {
        $container = self::getContainer();
        /** @var SubscriptionContext $subscriptions */
        $subscriptions = $container->get('jpm_martin_sylius_subscription.behat.context.setup.subscription');
        $subscriptions->iSubscribedTo('Coffee', 'COFFEE_MONTHLY');
        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = $container->get('sylius.behat.shared_storage');
        $mine = $sharedStorage->get('subscription');
        self::assertInstanceOf(SubscriptionInterface::class, $mine);

        foreach (['PUT' => 'pause', 'POST' => 'skip-renewal'] as $method => $action) {
            $this->client->request($method, $this->url($action, (int) $mine->getId()));
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $action);
        }
    }

    private function url(string $action, ?int $subscriptionId = null): string
    {
        return \sprintf('/%s/account/subscriptions/%d/%s', self::LOCALE, $subscriptionId ?? $this->othersSubscriptionId, $action);
    }

    private function assertTheSubscriptionIsUntouched(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $entityManager->clear();
        $subscription = $entityManager->find(SubscriptionInterface::class, $this->othersSubscriptionId);
        self::assertInstanceOf(SubscriptionInterface::class, $subscription);

        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState());
        $states = [];
        foreach ($subscription->getCycles() as $cycle) {
            $states[$cycle->getNumber()] = $cycle->getState();
            self::assertFalse($cycle->isSkipped());
        }
        self::assertSame([1 => SubscriptionCycleInterface::STATE_PAID, 2 => SubscriptionCycleInterface::STATE_SCHEDULED], $states);
    }
}
