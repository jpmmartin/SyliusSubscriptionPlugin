<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Functional\Api;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup\SubscriptionContext;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup\SubscriptionPlanContext;

/**
 * A store selling Coffee at $20.00 on a monthly plan with 10% off, and a quarterly one with 15% off,
 * where me@example.com, signed in to the shop API, subscribed to Coffee monthly on 1 January 2027, and
 * another customer did too.
 */
abstract class ShopSubscriptionApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected SubscriptionInterface $mySubscription;

    protected SubscriptionInterface $theirSubscription;

    private ?string $token = null;

    private string $password;

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
        $plans->theVariantOffersASubscriptionPlan($variant, 'COFFEE_QUARTERLY', '3', 'month', '15');

        /** @var UserContext $users */
        $users = $container->get('sylius.behat.context.setup.user');
        $users->thereIsUserIdentifiedBy('me@example.com');
        // Sylius's setup replaces the password it is given with a random one, which it keeps.
        $password = $sharedStorage->get('password');
        self::assertIsString($password);
        $this->password = $password;

        /** @var SubscriptionContext $subscriptions */
        $subscriptions = $container->get('jpm_martin_sylius_subscription.behat.context.setup.subscription');
        $subscriptions->thePaymentMethodChargesRenewalsThroughTheTestGateway('Card on file');
        $subscriptions->iSubscribedTo('Coffee', 'COFFEE_MONTHLY');
        $mine = $sharedStorage->get('subscription');
        self::assertInstanceOf(SubscriptionInterface::class, $mine);
        $this->mySubscription = $mine;
        $subscriptions->theCustomerSubscribedTo('other@example.com', 'Coffee', 'COFFEE_MONTHLY');
        $theirs = $sharedStorage->get('subscription');
        self::assertInstanceOf(SubscriptionInterface::class, $theirs);
        $this->theirSubscription = $theirs;
    }

    protected function tearDown(): void
    {
        /** @var CalendarHookContext $calendar */
        $calendar = self::getContainer()->get('sylius.behat.context.hook.calendar');
        $calendar->deleteTemporaryDate();

        parent::tearDown();
    }

    protected function signIn(): void
    {
        $this->client->request('POST', '/api/v2/shop/customers/token', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['email' => 'me@example.com', 'password' => $this->password]));
        self::assertResponseIsSuccessful();
        /** @var array{token: string} $response */
        $response = $this->responseJson();
        $this->token = $response['token'];
    }

    /** @param array<string, mixed>|null $body */
    protected function request(string $method, string $uri, ?array $body = null): void
    {
        $headers = ['HTTP_ACCEPT' => 'application/ld+json'];
        if (null !== $this->token) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token;
        }
        if (null !== $body) {
            $headers['CONTENT_TYPE'] = 'PATCH' === $method ? 'application/merge-patch+json' : 'application/ld+json';
        }

        $this->client->request($method, $uri, [], [], $headers, null === $body ? null : (string) json_encode($body));
    }

    /** @return array<string, mixed> */
    protected function responseJson(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        return $data;
    }

    /** @return list<string> the messages of the violations the response reports */
    protected function violationMessages(): array
    {
        $response = $this->responseJson();
        $violations = $response['violations'] ?? [];
        self::assertIsArray($violations);

        return array_values(array_map(static fn (array $violation): string => (string) $violation['message'], $violations));
    }

    protected function stored(SubscriptionInterface $subscription): SubscriptionInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $entityManager->clear();
        $stored = $entityManager->find(SubscriptionInterface::class, $subscription->getId());
        self::assertInstanceOf(SubscriptionInterface::class, $stored);

        return $stored;
    }

    protected function uriOf(SubscriptionInterface $subscription, string $suffix = ''): string
    {
        return \sprintf('/api/v2/shop/subscriptions/%d%s', $subscription->getId(), $suffix);
    }

    /**
     * Changes the stored subscription as the services would have: they have their own tests.
     *
     * @param callable(SubscriptionInterface): void $change
     */
    protected function storedSubscriptionIs(callable $change): void
    {
        $change($this->stored($this->mySubscription));
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $entityManager->flush();
    }

    protected static function cycleOf(SubscriptionInterface $subscription, int $number): SubscriptionCycleInterface
    {
        foreach ($subscription->getCycles() as $cycle) {
            if ($number === $cycle->getNumber()) {
                return $cycle;
            }
        }

        self::fail(\sprintf('The subscription has no cycle %d.', $number));
    }

    protected static function itemOf(SubscriptionInterface $subscription): SubscriptionItemInterface
    {
        $item = $subscription->getItems()->first();
        self::assertInstanceOf(SubscriptionItemInterface::class, $item);

        return $item;
    }
}
