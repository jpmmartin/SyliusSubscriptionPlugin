<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Functional\Api;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use JpmMartin\SyliusSubscriptionPlugin\Factory\SubscriptionPlanFactoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\RepeatableVariantsInterface;
use Sylius\Behat\Context\Hook\DoctrineORMContext;
use Sylius\Behat\Context\Setup\ChannelContext;
use Sylius\Behat\Context\Setup\ProductContext;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * PATCH /api/v2/shop/orders/{tokenValue}/items/{orderItemId}/subscription-terms, the shop API's counterpart of
 * the cart page's per-line dropdown (choose-subscription-per-cart-line, D8). CartAccessInterface's own rule
 * ("un carrito de otro cliente registrado") is covered at the unit level, in CartAccessTest: the same service
 * this handler and AddSubscriptionItemToCartHandler both now share.
 */
final class ChangingACartItemsSubscriptionTermsTest extends WebTestCase
{
    private KernelBrowser $client;

    private ChannelInterface $channel;

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
        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = $container->get('sylius.behat.shared_storage');
        /** @var ChannelInterface $channel */
        $channel = $sharedStorage->get('channel');
        $channel->setHostname('localhost');
        $this->channel = $channel;

        $this->coffee = $this->productVariant('Coffee', 2000);
        $this->tea = $this->productVariant('Tea', 1000);

        /** @var RepeatableVariantsInterface $repeatableVariants */
        $repeatableVariants = $container->get(RepeatableVariantsInterface::class);
        $repeatableVariants->markRepeatable($this->tea, true);

        $this->frequency('MONTHLY', 'Every month', 1, SubscriptionIntervalUnit::Month, 5);
        $this->frequency('BIWEEKLY', 'Every two weeks', 2, SubscriptionIntervalUnit::Week, 10);

        /** @var SubscriptionPlanFactoryInterface $planFactory */
        $planFactory = $container->get('jpm_martin_sylius_subscription.factory.subscription_plan');
        $plan = $planFactory->createForVariant($this->coffee);
        $plan->setCode('COFFEE_WEEKLY');
        $plan->setName('COFFEE_WEEKLY');
        $plan->setIntervalCount(1);
        $plan->setIntervalUnit(SubscriptionIntervalUnit::Week);
        $plan->setDiscountPercentage(10);
        $this->entityManager()->persist($plan);
        $this->entityManager()->flush();
    }

    public function testChoosingAFrequencyForALine(): void
    {
        $token = $this->pickUpACart();
        $itemId = $this->addOneOff($token, $this->tea);

        $this->patchTerms($token, $itemId, ['subscriptionFrequency' => 'MONTHLY']);

        self::assertResponseIsSuccessful();
        $item = $this->onlyItemOfTheResponse();
        self::assertSame('MONTHLY', $item['subscriptionFrequency']);
        self::assertNull($item['subscriptionPlan']);
        self::assertSame(950, $item['unitPrice']);

        $cart = $this->getCart($token);
        $line = $cart->getItems()->first();
        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $line);
        self::assertSame('MONTHLY', $line->getSubscriptionFrequency()?->getCode());
    }

    public function testAPlanAndAFrequencyTogetherAreRefused(): void
    {
        $token = $this->pickUpACart();
        $itemId = $this->addOneOff($token, $this->coffee);

        $this->patchTerms($token, $itemId, ['subscriptionPlan' => 'COFFEE_WEEKLY', 'subscriptionFrequency' => 'MONTHLY']);

        self::assertResponseStatusCodeSame(422);
        $cart = $this->getCart($token);
        $line = $cart->getItems()->first();
        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $line);
        self::assertNull($line->getSubscriptionPlan());
        self::assertNull($line->getSubscriptionFrequency());
    }

    public function testAnOptionTheLineDoesNotHaveIsRefused(): void
    {
        $token = $this->pickUpACart();
        // Coffee's variant cannot be repeated and has no BIWEEKLY plan: BIWEEKLY is not among its options.
        $itemId = $this->addOneOff($token, $this->coffee);

        $this->patchTerms($token, $itemId, ['subscriptionFrequency' => 'BIWEEKLY']);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('This subscription option is not offered for this item.', $this->violationMessages());
        $cart = $this->getCart($token);
        $line = $cart->getItems()->first();
        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $line);
        self::assertNull($line->getSubscriptionFrequency());
    }

    public function testALineOfAnotherCartIsNotFound(): void
    {
        $token = $this->pickUpACart();
        $this->addOneOff($token, $this->tea);

        $otherToken = $this->pickUpACart();
        $otherItemId = $this->addOneOff($otherToken, $this->tea);

        // The other cart's own item, patched through this cart's token: not this cart's line.
        $this->patchTerms($token, $otherItemId, ['subscriptionFrequency' => 'MONTHLY']);

        self::assertResponseStatusCodeSame(404);
    }

    private function pickUpACart(): string
    {
        $this->post('/api/v2/shop/orders', ['localeCode' => 'en_US']);
        self::assertResponseStatusCodeSame(201);

        /** @var array{tokenValue: string} $cart */
        $cart = $this->responseJson();

        return $cart['tokenValue'];
    }

    private function addOneOff(string $token, ProductVariantInterface $variant): int
    {
        $this->post(\sprintf('/api/v2/shop/orders/%s/items', $token), ['productVariant' => $variant->getCode(), 'quantity' => 1]);
        self::assertResponseStatusCodeSame(201);
        $item = $this->onlyItemOfTheResponse();

        return (int) $item['id'];
    }

    /** @param array<string, mixed> $body */
    private function patchTerms(string $token, int $itemId, array $body): void
    {
        $this->client->request('PATCH', \sprintf('/api/v2/shop/orders/%s/items/%d/subscription-terms', $token, $itemId), [], [], [
            'CONTENT_TYPE' => 'application/merge-patch+json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $body */
    private function post(string $uri, array $body): void
    {
        $this->client->request('POST', $uri, [], [], [
            'CONTENT_TYPE' => 'application/ld+json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function onlyItemOfTheResponse(): array
    {
        /** @var array{items: list<array<string, mixed>>} $cart */
        $cart = $this->responseJson();
        self::assertCount(1, $cart['items']);

        return $cart['items'][0];
    }

    /** @return array<mixed> */
    private function responseJson(): array
    {
        $response = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($response);

        return $response;
    }

    /** @return list<string> */
    private function violationMessages(): array
    {
        /** @var array{violations?: list<array{message: string}>} $response */
        $response = $this->responseJson();

        return array_map(static fn (array $violation): string => $violation['message'], $response['violations'] ?? []);
    }

    private function getCart(string $token): OrderInterface
    {
        $this->entityManager()->clear();

        /** @var OrderRepositoryInterface<OrderInterface> $orders */
        $orders = self::getContainer()->get('sylius.repository.order');
        $cart = $orders->findCartByTokenValue($token);
        self::assertInstanceOf(OrderInterface::class, $cart);

        return $cart;
    }

    private function frequency(string $code, string $name, int $intervalCount, SubscriptionIntervalUnit $unit, int $discount): void
    {
        /** @var FactoryInterface<\JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface> $factory */
        $factory = self::getContainer()->get('jpm_martin_sylius_subscription.factory.subscription_frequency');
        $frequency = $factory->createNew();
        $frequency->setCode($code);
        $frequency->setName($name);
        $frequency->setIntervalCount($intervalCount);
        $frequency->setIntervalUnit($unit);
        $frequency->setDiscountPercentage($discount);
        $frequency->addChannel($this->channel);
        $this->entityManager()->persist($frequency);
    }

    private function productVariant(string $name, int $price): ProductVariantInterface
    {
        /** @var ProductContext $products */
        $products = self::getContainer()->get('sylius.behat.context.setup.product');
        $products->storeHasAProductPricedAt($name, $price, $this->channel);

        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = self::getContainer()->get('sylius.behat.shared_storage');
        /** @var ProductInterface $product */
        $product = $sharedStorage->get('product');
        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariantInterface::class, $variant);

        return $variant;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }
}
