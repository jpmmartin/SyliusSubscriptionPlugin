<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Functional\Api;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use JpmMartin\SyliusSubscriptionPlugin\Factory\SubscriptionPlanFactoryInterface;
use Sylius\Behat\Context\Hook\DoctrineORMContext;
use Sylius\Behat\Context\Setup\ChannelContext;
use Sylius\Behat\Context\Setup\ProductContext;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** POST /api/v2/shop/orders/{tokenValue}/subscription-items, next to Sylius's own /items. */
final class AddingASubscriptionItemTest extends WebTestCase
{
    private const NOT_OFFERED = 'This subscription plan is not offered for this product variant.';

    private KernelBrowser $client;

    private ProductVariantInterface $coffee;

    private ProductVariantInterface $tea;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();

        /** @var DoctrineORMContext $database */
        $database = $container->get('sylius.behat.context.hook.doctrine_orm');
        $database->purgeDatabase();

        /** @var ChannelContext $channels */
        $channels = $container->get('sylius.behat.context.setup.channel');
        $channels->storeOperatesOnASingleChannelInUnitedStates();

        $this->coffee = $this->productVariant('Coffee', 2000);
        $this->tea = $this->productVariant('Tea', 1000);

        $this->addPlan($this->coffee, 'COFFEE_MONTHLY', 10);
        $this->addPlan($this->coffee, 'COFFEE_RETIRED', 10, enabled: false);
        $this->addPlan($this->tea, 'TEA_MONTHLY', 5);
    }

    public function testSubscribingThroughTheApi(): void
    {
        $token = $this->pickUpACart();

        $this->post(\sprintf('/api/v2/shop/orders/%s/subscription-items', $token), [
            'productVariant' => $this->coffee->getCode(),
            'subscriptionPlan' => 'COFFEE_MONTHLY',
            'quantity' => 1,
        ]);

        self::assertResponseStatusCodeSame(201);
        $items = $this->getCart($token)->getItems();
        self::assertCount(1, $items);
        $item = $items->first();
        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $item);
        self::assertSame('COFFEE_MONTHLY', $item->getSubscriptionPlan()?->getCode());
        self::assertSame(1800, $item->getUnitPrice());
    }

    public function testAPlanOfAnotherVariantIsRefused(): void
    {
        $token = $this->pickUpACart();

        $this->post(\sprintf('/api/v2/shop/orders/%s/subscription-items', $token), [
            'productVariant' => $this->coffee->getCode(),
            'subscriptionPlan' => 'TEA_MONTHLY',
            'quantity' => 1,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertContains(self::NOT_OFFERED, $this->violationMessages());
        self::assertCount(0, $this->getCart($token)->getItems());
    }

    public function testADisabledPlanIsRefused(): void
    {
        $token = $this->pickUpACart();

        $this->post(\sprintf('/api/v2/shop/orders/%s/subscription-items', $token), [
            'productVariant' => $this->coffee->getCode(),
            'subscriptionPlan' => 'COFFEE_RETIRED',
            'quantity' => 1,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertContains(self::NOT_OFFERED, $this->violationMessages());
        self::assertCount(0, $this->getCart($token)->getItems());
    }

    public function testAOneOffLineAndASubscriptionLineOfTheSameVariantStayApart(): void
    {
        $token = $this->pickUpACart();

        $this->post(\sprintf('/api/v2/shop/orders/%s/items', $token), [
            'productVariant' => $this->coffee->getCode(),
            'quantity' => 1,
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->post(\sprintf('/api/v2/shop/orders/%s/subscription-items', $token), [
            'productVariant' => $this->coffee->getCode(),
            'subscriptionPlan' => 'COFFEE_MONTHLY',
            'quantity' => 1,
        ]);
        self::assertResponseStatusCodeSame(201);

        $plans = [];
        foreach ($this->getCart($token)->getItems() as $item) {
            self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $item);
            $plans[] = $item->getSubscriptionPlan()?->getCode();
        }
        sort($plans);

        self::assertSame([null, 'COFFEE_MONTHLY'], $plans);
    }

    private function pickUpACart(): string
    {
        $this->post('/api/v2/shop/orders', ['localeCode' => 'en_US']);
        self::assertResponseStatusCodeSame(201);

        /** @var array{tokenValue: string} $cart */
        $cart = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $cart['tokenValue'];
    }

    /** @param array<string, mixed> $body */
    private function post(string $uri, array $body): void
    {
        $this->client->request('POST', $uri, [], [], [
            'CONTENT_TYPE' => 'application/ld+json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    private function violationMessages(): array
    {
        /** @var array{violations?: list<array{message: string}>} $response */
        $response = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return array_map(static fn (array $violation): string => $violation['message'], $response['violations'] ?? []);
    }

    private function getCart(string $token): OrderInterface
    {
        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $manager->clear();

        /** @var OrderRepositoryInterface<OrderInterface> $orders */
        $orders = self::getContainer()->get('sylius.repository.order');
        $cart = $orders->findCartByTokenValue($token);
        self::assertInstanceOf(OrderInterface::class, $cart);

        return $cart;
    }

    private function productVariant(string $name, int $price): ProductVariantInterface
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

    private function addPlan(ProductVariantInterface $variant, string $code, int $discountPercentage, bool $enabled = true): void
    {
        /** @var SubscriptionPlanFactoryInterface $factory */
        $factory = self::getContainer()->get('jpm_martin_sylius_subscription.factory.subscription_plan');
        $plan = $factory->createForVariant($variant);
        $plan->setCode($code);
        $plan->setName($code);
        $plan->setIntervalCount(1);
        $plan->setIntervalUnit(SubscriptionIntervalUnit::Month);
        $plan->setDiscountPercentage($discountPercentage);
        $plan->setEnabled($enabled);

        /** @var RepositoryInterface<SubscriptionPlanInterface> $plans */
        $plans = self::getContainer()->get('jpm_martin_sylius_subscription.repository.subscription_plan');
        $plans->add($plan);
    }
}
