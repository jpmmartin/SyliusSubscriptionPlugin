<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Functional\Api;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Factory\SubscriptionPlanFactoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\CartRepeaterInterface;
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
 * "Repeat this cart" through the shop API: GET /api/v2/shop/subscription-frequencies and
 * PATCH /api/v2/shop/orders/{tokenValue}/subscription-frequency, and the plan or frequency of each
 * line of the cart it answers with.
 */
final class RepeatingACartTest extends WebTestCase
{
    private const NOT_OFFERED = 'This subscription frequency is not offered for this cart.';

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
        // The API picks the channel by hostname once there is more than one.
        $channel->setHostname('localhost');
        $this->channel = $channel;

        $this->coffee = $this->productVariant('Coffee', 2000);
        $this->tea = $this->productVariant('Tea', 1000);

        /** @var RepeatableVariantsInterface $repeatableVariants */
        $repeatableVariants = $container->get(RepeatableVariantsInterface::class);
        $repeatableVariants->markRepeatable($this->tea, true);

        /** @var SubscriptionPlanFactoryInterface $planFactory */
        $planFactory = $container->get('jpm_martin_sylius_subscription.factory.subscription_plan');
        $plan = $planFactory->createForVariant($this->coffee);
        $plan->setCode('COFFEE_WEEKLY');
        $plan->setName('COFFEE_WEEKLY');
        $plan->setIntervalCount(1);
        $plan->setIntervalUnit(SubscriptionIntervalUnit::Week);
        $plan->setDiscountPercentage(10);
        $this->entityManager()->persist($plan);

        $this->frequency('MONTHLY', 'Every month', 1, SubscriptionIntervalUnit::Month, 5, $this->channel);
        $this->frequency('EVERY_TWO_WEEKS', 'Every two weeks', 2, SubscriptionIntervalUnit::Week, 0, $this->channel);
        $this->frequency('RETIRED', 'Retired', 1, SubscriptionIntervalUnit::Year, 20, $this->channel)->disable();
        $channels->theStoreOperatesOnAChannelNamed('France', 'EUR', 'fr.example.com');
        /** @var ChannelInterface $france */
        $france = $sharedStorage->get('channel');
        $this->frequency('FRANCE_MONTHLY', 'Chaque mois', 1, SubscriptionIntervalUnit::Month, 5, $france);
        $this->entityManager()->flush();
    }

    public function testListingTheFrequenciesOfTheChannel(): void
    {
        $this->client->request('GET', '/api/v2/shop/subscription-frequencies', [], [], ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertResponseIsSuccessful();
        /** @var array{'hydra:member'?: list<array<string, mixed>>, member?: list<array<string, mixed>>} $response */
        $response = $this->responseJson();
        $members = $response['hydra:member'] ?? $response['member'] ?? [];
        self::assertSame(
            [
                ['code' => 'MONTHLY', 'name' => 'Every month', 'intervalCount' => 1, 'intervalUnit' => 'month', 'discountPercentage' => 5],
                ['code' => 'EVERY_TWO_WEEKS', 'name' => 'Every two weeks', 'intervalCount' => 2, 'intervalUnit' => 'week', 'discountPercentage' => 0],
            ],
            array_map(
                static fn (array $member): array => array_intersect_key($member, array_flip(['code', 'name', 'intervalCount', 'intervalUnit', 'discountPercentage'])),
                $members,
            ),
            'The disabled frequency and the one of another channel are left out.',
        );
    }

    public function testChoosingAFrequencyRepeatsTheCartsRepeatableLines(): void
    {
        $token = $this->pickUpACart();
        $this->addOneOff($token, $this->tea);

        $this->patchFrequency($token, 'MONTHLY');

        self::assertResponseIsSuccessful();
        $item = $this->onlyItemOfTheResponse();
        self::assertSame('MONTHLY', $item['subscriptionFrequency']);
        self::assertNull($item['subscriptionPlan']);
        self::assertSame(950, $item['unitPrice']);
        self::assertSame('MONTHLY', $this->cartRepeater()->getFrequency($this->getCart($token))?->getCode());

        $this->client->request('GET', \sprintf('/api/v2/shop/orders/%s/items/%s', $token, (string) $item['id']), [], [], ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful();
        /** @var array{subscriptionFrequency?: string|null} $line */
        $line = $this->responseJson();
        self::assertSame('MONTHLY', $line['subscriptionFrequency'] ?? null, 'A single line of the cart tells it too.');
    }

    public function testStoppingRepeatingTheCart(): void
    {
        $token = $this->pickUpACart();
        $this->addOneOff($token, $this->tea);
        $this->patchFrequency($token, 'MONTHLY');

        $this->patchFrequency($token, null);

        self::assertResponseIsSuccessful();
        $item = $this->onlyItemOfTheResponse();
        self::assertNull($item['subscriptionFrequency']);
        self::assertSame(1000, $item['unitPrice']);
        self::assertNull($this->cartRepeater()->getFrequency($this->getCart($token)));
    }

    public function testAFrequencyTheCartIsNotOfferedIsRefused(): void
    {
        $token = $this->pickUpACart();
        $this->addOneOff($token, $this->tea);

        foreach (['RETIRED', 'FRANCE_MONTHLY', 'UNKNOWN'] as $code) {
            $this->patchFrequency($token, $code);

            self::assertResponseStatusCodeSame(422, $code);
            self::assertContains(self::NOT_OFFERED, $this->violationMessages(), $code);
        }
        self::assertNull($this->cartRepeater()->getFrequency($this->getCart($token)));
    }

    public function testTheCartTellsThePlanOfALine(): void
    {
        $token = $this->pickUpACart();

        $this->post(\sprintf('/api/v2/shop/orders/%s/subscription-items', $token), [
            'productVariant' => $this->coffee->getCode(),
            'subscriptionPlan' => 'COFFEE_WEEKLY',
            'quantity' => 1,
        ]);

        self::assertResponseStatusCodeSame(201);
        $item = $this->onlyItemOfTheResponse();
        self::assertSame('COFFEE_WEEKLY', $item['subscriptionPlan']);
        self::assertNull($item['subscriptionFrequency']);
    }

    private function pickUpACart(): string
    {
        $this->post('/api/v2/shop/orders', ['localeCode' => 'en_US']);
        self::assertResponseStatusCodeSame(201);

        /** @var array{tokenValue: string} $cart */
        $cart = $this->responseJson();

        return $cart['tokenValue'];
    }

    private function addOneOff(string $token, ProductVariantInterface $variant): void
    {
        $this->post(\sprintf('/api/v2/shop/orders/%s/items', $token), ['productVariant' => $variant->getCode(), 'quantity' => 1]);
        self::assertResponseStatusCodeSame(201);
    }

    private function patchFrequency(string $token, ?string $code): void
    {
        $this->client->request('PATCH', \sprintf('/api/v2/shop/orders/%s/subscription-frequency', $token), [], [], [
            'CONTENT_TYPE' => 'application/merge-patch+json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ], json_encode(['subscriptionFrequency' => $code], \JSON_THROW_ON_ERROR));
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

    private function cartRepeater(): CartRepeaterInterface
    {
        /** @var CartRepeaterInterface $cartRepeater */
        $cartRepeater = self::getContainer()->get(CartRepeaterInterface::class);

        return $cartRepeater;
    }

    private function frequency(string $code, string $name, int $intervalCount, SubscriptionIntervalUnit $unit, int $discount, ChannelInterface $channel): SubscriptionFrequencyInterface
    {
        /** @var FactoryInterface<SubscriptionFrequencyInterface> $factory */
        $factory = self::getContainer()->get('jpm_martin_sylius_subscription.factory.subscription_frequency');
        $frequency = $factory->createNew();
        $frequency->setCode($code);
        $frequency->setName($name);
        $frequency->setIntervalCount($intervalCount);
        $frequency->setIntervalUnit($unit);
        $frequency->setDiscountPercentage($discount);
        $frequency->addChannel($channel);
        $this->entityManager()->persist($frequency);

        return $frequency;
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
