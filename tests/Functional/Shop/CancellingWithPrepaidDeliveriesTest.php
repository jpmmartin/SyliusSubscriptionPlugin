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
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Domain\ProcessingRenewalsContext;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup\SubscriptionContext;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup\SubscriptionPlanContext;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

/**
 * Coffee on a monthly plan charged by blocks of three deliveries, subscribed to on 1 January. Its
 * customer cancels it from their account after the first delivery: February and March still come,
 * nothing more is charged, and then it is cancelled.
 */
final class CancellingWithPrepaidDeliveriesTest extends WebTestCase
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
        $plans->theSubscriptionPlanChargesDeliveriesAtATime('COFFEE_MONTHLY', '3');

        /** @var UserContext $users */
        $users = $container->get('sylius.behat.context.setup.user');
        $users->thereIsUserIdentifiedBy('me@example.com');
        $me = $sharedStorage->get('user');
        self::assertInstanceOf(ShopUserInterface::class, $me);

        /** @var SubscriptionContext $subscriptions */
        $subscriptions = $container->get('jpm_martin_sylius_subscription.behat.context.setup.subscription');
        $subscriptions->thePaymentMethodChargesRenewalsThroughTheTestGateway('Card on file');
        $subscriptions->iSubscribedTo('Coffee', 'COFFEE_MONTHLY');
        $subscription = $sharedStorage->get('subscription');
        self::assertInstanceOf(SubscriptionInterface::class, $subscription);
        $this->subscriptionId = (int) $subscription->getId();

        $this->client->loginUser($me, 'shop');
    }

    protected function tearDown(): void
    {
        /** @var CalendarHookContext $calendar */
        $calendar = self::getContainer()->get('sylius.behat.context.hook.calendar');
        $calendar->deleteTemporaryDate();

        parent::tearDown();
    }

    public function testTheCustomerReceivesTheDeliveriesPaidForAndIsChargedNothingMoreBeforeItIsCancelled(): void
    {
        $crawler = $this->client->request('GET', $this->showPath());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('2 deliveries paid for, the last on Mar 1, 2027', trim($crawler->filter('[data-test-subscription-prepaid-deliveries]')->text()));
        $form = $crawler->filter('form[action$="/cancel"]')->form();
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        $crawler = $this->client->request('GET', $this->showPath());
        self::assertSame('Cancelled after its last delivery paid for, on Mar 1, 2027', trim($crawler->filter('[data-test-subscription-prepaid-deliveries]')->text()));
        self::assertCount(0, $crawler->filter('[data-test-cancel]'), 'It is already being cancelled.');
        $subscription = $this->subscription();
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState());
        self::assertTrue($subscription->cancelsAfterPrepaidDeliveries());

        $this->renewalsAreProcessed('2027-02-01 09:00');
        $this->renewalsAreProcessed('2027-03-01 09:00');

        $subscription = $this->subscription();
        self::assertSame(SubscriptionInterface::STATE_CANCELLED, $subscription->getState());
        $cycles = array_values($subscription->getCycles()->toArray());
        self::assertSame(
            [SubscriptionCycleInterface::STATE_PAID, SubscriptionCycleInterface::STATE_PAID, SubscriptionCycleInterface::STATE_PAID],
            array_map(static fn (SubscriptionCycleInterface $cycle): string => $cycle->getState(), $cycles),
            'Both deliveries came, and no charge was scheduled after them.',
        );
        $gateway = self::getContainer()->get('jpm_martin_sylius_subscription.test.scripted_gateway');
        self::assertInstanceOf(ScriptedGateway::class, $gateway);
        self::assertSame([], $gateway->requests());
    }

    private function renewalsAreProcessed(string $dateTime): void
    {
        /** @var ProcessingRenewalsContext $renewals */
        $renewals = self::getContainer()->get('jpm_martin_sylius_subscription.behat.context.domain.processing_renewals');
        $renewals->theRenewalsDueOnAreProcessed($dateTime);
    }

    private function showPath(): string
    {
        return \sprintf('/%s/account/subscriptions/%d', self::LOCALE, $this->subscriptionId);
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
}
