<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Functional\Shop;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;
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
 * A subscription to Coffee within its minimum commitment of six cycles, one paid: the account offers
 * neither cancelling nor pausing it, and a request that forces either is refused.
 */
final class CancellingWithinACommitmentTest extends WebTestCase
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
        $plans->theVariantOffersASubscriptionPlan($variant, 'COFFEE_MONTHLY', '1', 'month');
        $plans->theSubscriptionPlanHasAMinimumCommitment('COFFEE_MONTHLY', '6');

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

    public function testForcingTheCancellationOrThePauseIsRefusedAndTheSubscriptionStaysActive(): void
    {
        $crawler = $this->client->request('GET', $this->showPath());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-test-cancel]'), 'Cancelling is not offered.');
        self::assertCount(0, $crawler->filter('[data-test-pause]'), 'Pausing is not offered.');
        self::assertSame('5 more paid renewals before you can cancel or pause it', trim($crawler->filter('[data-test-subscription-commitment]')->text()));

        $token = $this->aValidTokenForItsForms();
        foreach (['cancel', 'pause'] as $action) {
            $this->client->request('POST', $this->showPath() . '/' . $action, ['_method' => 'PUT', '_csrf_token' => $token]);
            self::assertSame(400, $this->client->getResponse()->getStatusCode(), $action);
        }

        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $this->subscription()->getState());

        // The same request, once nothing commits the subscription any more, cancels it.
        $this->onlyItem()->setCommitmentCycles(1);
        $this->entityManager()->flush();
        $this->client->request('POST', $this->showPath() . '/cancel', ['_method' => 'PUT', '_csrf_token' => $token]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(SubscriptionInterface::STATE_CANCELLED, $this->subscription()->getState());
    }

    /** The token the account's forms carry, taken while the commitment is lifted, which the session keeps. */
    private function aValidTokenForItsForms(): string
    {
        $item = $this->onlyItem();
        $item->setCommitmentCycles(null);
        $this->entityManager()->flush();

        $crawler = $this->client->request('GET', $this->showPath());
        $token = $crawler->filter('form[action$="/cancel"] input[name="_csrf_token"]')->attr('value');
        self::assertNotNull($token, 'Without a commitment, cancelling is offered.');

        $item = $this->onlyItem();
        $item->setCommitmentCycles(6);
        $this->entityManager()->flush();

        return $token;
    }

    private function showPath(): string
    {
        return \sprintf('/%s/account/subscriptions/%d', self::LOCALE, $this->subscriptionId);
    }

    private function subscription(): SubscriptionInterface
    {
        $this->entityManager()->clear();
        $subscription = $this->entityManager()->find(SubscriptionInterface::class, $this->subscriptionId);
        self::assertInstanceOf(SubscriptionInterface::class, $subscription);

        return $subscription;
    }

    private function onlyItem(): SubscriptionItemInterface
    {
        $item = $this->subscription()->getItems()->first();
        self::assertInstanceOf(SubscriptionItemInterface::class, $item);

        return $item;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }
}
