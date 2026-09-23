<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Functional\Shop;

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
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Adding to the cart from the product page is an action of Sylius's add-to-cart live component.
 * Behat's browserless driver cannot run it: it posts the form to the product page and gets a 405.
 * So it is driven here the way the browser's live-component script drives it: the product page is
 * opened, the component's props are read from the page, and its addToCart action is posted with
 * the chosen values, all within one browser session.
 *
 * The store is set up with Sylius's own Behat setup services, which the test application loads in
 * the test environment, so these tests start from the same store the Behat scenarios do.
 */
final class AddingASubscriptionToTheCartTest extends WebTestCase
{
    private const LOCALE = 'en_US';

    private const COMPONENT = 'sylius_shop:product:add_to_cart_form';

    private const FORM = 'sylius_shop_add_to_cart';

    private KernelBrowser $client;

    private ProductInterface $product;

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

        /** @var ProductContext $products */
        $products = $container->get('sylius.behat.context.setup.product');
        $products->storeHasAProductPricedAt('Coffee', 2000);

        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = $container->get('sylius.behat.shared_storage');
        /** @var ProductInterface $product */
        $product = $sharedStorage->get('product');
        $this->product = $product;

        $this->addPlan('COFFEE_MONTHLY', 10);
    }

    public function testSubscribingToAProductFromItsPage(): void
    {
        $this->addToCart('COFFEE_MONTHLY');
        self::assertLessThan(400, $this->client->getResponse()->getStatusCode());

        $item = $this->getOnlyCartItem();
        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $item);
        self::assertSame('COFFEE_MONTHLY', $item->getSubscriptionPlan()?->getCode());

        $this->client->request('GET', \sprintf('/%s/cart/', self::LOCALE));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test-cart-item-subscription-plan="COFFEE_MONTHLY"]');
    }

    public function testBuyingOnceAProductThatAlsoOffersPlans(): void
    {
        $this->addToCart('');
        self::assertLessThan(400, $this->client->getResponse()->getStatusCode());

        $item = $this->getOnlyCartItem();
        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $item);
        self::assertNull($item->getSubscriptionPlan());

        $this->client->request('GET', \sprintf('/%s/cart/', self::LOCALE));
        self::assertSelectorNotExists('[data-test-cart-item-subscription-plan]');
    }

    public function testBuyingOnceAndSubscribingToTheSameVariantMakeTwoLines(): void
    {
        $this->addToCart('');
        $this->addToCart('COFFEE_MONTHLY');

        $carts = $this->getCarts();
        self::assertCount(1, $carts);

        $plansOfTheLines = [];
        foreach ($carts[0]->getItems() as $item) {
            self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $item);
            $plansOfTheLines[] = $item->getSubscriptionPlan()?->getCode();
        }
        sort($plansOfTheLines);

        self::assertSame([null, 'COFFEE_MONTHLY'], $plansOfTheLines);
    }

    public function testSubscribingTwiceOnTheSamePlanAddsToTheSameLine(): void
    {
        $this->addToCart('COFFEE_MONTHLY');
        $this->addToCart('COFFEE_MONTHLY');

        $item = $this->getOnlyCartItem();
        self::assertSame(2, $item->getQuantity());
    }

    public function testAPlanTheVariantDoesNotOfferIsRefused(): void
    {
        $this->addToCart('SOMEONE_ELSES_PLAN');

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->getCarts());
    }

    private function addToCart(string $planCode): void
    {
        $crawler = $this->client->request('GET', \sprintf('/%s/products/%s', self::LOCALE, $this->product->getSlug()));
        self::assertResponseIsSuccessful();

        $component = $crawler->filter(\sprintf('[data-live-name-value="%s"]', self::COMPONENT));
        self::assertCount(1, $component, 'The product page does not render the add-to-cart component.');

        /** @var array<string, mixed> $props */
        $props = json_decode((string) $component->attr('data-live-props-value'), true, 512, \JSON_THROW_ON_ERROR);
        $updated = [
            self::FORM . '.cartItem.quantity' => '1',
            self::FORM . '.cartItem.subscriptionPlan' => $planCode,
        ];

        $this->client->request('POST', $component->attr('data-live-url-value') . '/addToCart', [
            'data' => json_encode(['props' => $props, 'updated' => $updated, 'validatedFields' => array_keys($updated)], \JSON_THROW_ON_ERROR),
        ]);
    }

    private function getOnlyCartItem(): OrderItemInterface
    {
        $carts = $this->getCarts();
        self::assertCount(1, $carts);

        $items = $carts[0]->getItems();
        self::assertCount(1, $items);

        $item = $items->first();
        self::assertInstanceOf(OrderItemInterface::class, $item);

        return $item;
    }

    /** @return list<OrderInterface> */
    private function getCarts(): array
    {
        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $manager->clear();

        /** @var RepositoryInterface<OrderInterface> $orders */
        $orders = self::getContainer()->get('sylius.repository.order');

        /** @var list<OrderInterface> $carts */
        $carts = $orders->findBy(['state' => OrderInterface::STATE_CART]);

        return $carts;
    }

    private function addPlan(string $code, int $discountPercentage): void
    {
        $variant = $this->product->getVariants()->first();
        self::assertInstanceOf(ProductVariantInterface::class, $variant);

        /** @var SubscriptionPlanFactoryInterface $factory */
        $factory = self::getContainer()->get('jpm_martin_sylius_subscription.factory.subscription_plan');
        $plan = $factory->createForVariant($variant);
        $plan->setCode($code);
        $plan->setName($code);
        $plan->setIntervalCount(1);
        $plan->setIntervalUnit(SubscriptionIntervalUnit::Month);
        $plan->setDiscountPercentage($discountPercentage);

        /** @var RepositoryInterface<SubscriptionPlanInterface> $plans */
        $plans = self::getContainer()->get('jpm_martin_sylius_subscription.repository.subscription_plan');
        $plans->add($plan);
    }
}
