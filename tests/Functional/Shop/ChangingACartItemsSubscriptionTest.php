<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Functional\Shop;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Controller\ChangeCartItemSubscriptionTermsAction;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\RepeatableVariantsInterface;
use Sylius\Behat\Context\Hook\DoctrineORMContext;
use Sylius\Behat\Context\Setup\ChannelContext;
use Sylius\Behat\Context\Setup\ProductContext;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The per-line dropdown on the cart page (choose-subscription-per-cart-line, D5): POST
 * /{_locale}/cart/items/{id}/subscription-terms. The route has no page of its own yet (D6, task 3.2), so each test
 * drives it the way a browser without JavaScript would: a plain POST with a real cart, established the way
 * AddingASubscriptionToTheCartTest does it, and a CSRF token taken from the same token manager the controller
 * reads, rather than scraped off a form that does not exist yet.
 */
final class ChangingACartItemsSubscriptionTest extends WebTestCase
{
    private const LOCALE = 'en_US';

    private const COMPONENT = 'sylius_shop:product:add_to_cart_form';

    private const FORM = 'sylius_shop_add_to_cart';

    private KernelBrowser $client;

    private ChannelInterface $channel;

    private ProductInterface $product;

    private string $variantCode;

    private SubscriptionFrequencyInterface $monthly;

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
        $this->channel = $channel;

        /** @var ProductContext $products */
        $products = $container->get('sylius.behat.context.setup.product');
        $products->storeHasAProductPricedAt('Tea', 1000, $this->channel);
        /** @var ProductInterface $product */
        $product = $sharedStorage->get('product');
        $this->product = $product;
        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariantInterface::class, $variant);
        $this->variantCode = (string) $variant->getCode();

        /** @var RepeatableVariantsInterface $repeatableVariants */
        $repeatableVariants = $container->get(RepeatableVariantsInterface::class);
        $repeatableVariants->markRepeatable($variant, true);

        /** @var FactoryInterface<SubscriptionFrequencyInterface> $frequencyFactory */
        $frequencyFactory = $container->get('jpm_martin_sylius_subscription.factory.subscription_frequency');
        $monthly = $frequencyFactory->createNew();
        $monthly->setCode('MONTHLY');
        $monthly->setName('MONTHLY');
        $monthly->setIntervalCount(1);
        $monthly->setIntervalUnit(SubscriptionIntervalUnit::Month);
        $monthly->setDiscountPercentage(5);
        $monthly->addChannel($this->channel);
        $this->entityManager()->persist($monthly);
        $this->entityManager()->flush();
        $this->monthly = $monthly;
    }

    public function testTheChangeIsAppliedAndNotified(): void
    {
        $item = $this->addToCartAndGetItem();

        $this->changeTerms($item, 'frequency:MONTHLY', $this->validToken());

        self::assertResponseRedirects(\sprintf('/%s/cart/', self::LOCALE));
        self::assertContains('jpm_martin_sylius_subscription.cart.item_subscription_changed', $this->flashes()['success'] ?? []);
        $changed = $this->reloadItem($item);
        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $changed);
        self::assertSame('MONTHLY', $changed->getSubscriptionFrequency()?->getCode());
    }

    public function testAnInvalidCsrfTokenIsRefused(): void
    {
        $item = $this->addToCartAndGetItem();

        $this->changeTerms($item, 'frequency:MONTHLY', 'forged');

        self::assertResponseStatusCodeSame(403);
        $unchanged = $this->reloadItem($item);
        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $unchanged);
        self::assertNull($unchanged->getSubscriptionFrequency());
    }

    public function testALineOfAnotherCartIsNotFound(): void
    {
        $this->addToCartAndGetItem();

        $otherCart = $this->anotherCartWithALine();
        $otherItem = $otherCart->getItems()->first();
        self::assertInstanceOf(OrderItemInterface::class, $otherItem);

        $this->changeTerms($otherItem, 'frequency:MONTHLY', $this->validToken());

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnOptionNotOfferedIsRefusedWithAnErrorAndNoChange(): void
    {
        $item = $this->addToCartAndGetItem();

        $this->changeTerms($item, 'plan:SOMEONE_ELSES_PLAN', $this->validToken());

        self::assertResponseRedirects(\sprintf('/%s/cart/', self::LOCALE));
        self::assertContains('jpm_martin_sylius_subscription.cart.subscription_option_not_offered', $this->flashes()['error'] ?? []);
        $unchanged = $this->reloadItem($item);
        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $unchanged);
        self::assertNull($unchanged->getSubscriptionFrequency());
        self::assertNull($unchanged->getSubscriptionPlan());
    }

    private function changeTerms(OrderItemInterface $item, string $value, string $csrfToken): void
    {
        $this->client->request('POST', \sprintf('/%s/cart/items/%d/subscription-terms', self::LOCALE, (int) $item->getId()), [
            ChangeCartItemSubscriptionTermsAction::FIELD => $value,
            '_csrf_token' => $csrfToken,
        ]);
    }

    /**
     * The token manager reads and writes the token through the session of the request handling it, and there is
     * none active between client requests in a test. The last request's own session is real, though - the guest
     * cart is tracked through it - so it is pushed onto the request stack just for this call, the way the token
     * manager would see it from inside a real request to a page carrying this token. Saving it explicitly matters
     * here: a real request persists the session as part of its response, which this call, outside any request,
     * never gets, so without it the token would only ever exist in this one PHP object, not in session storage.
     */
    private function validToken(): string
    {
        $request = $this->client->getRequest();
        self::assertNotNull($request);
        $session = $request->getSession();

        /** @var \Symfony\Component\HttpFoundation\RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);

        try {
            /** @var CsrfTokenManagerInterface $csrf */
            $csrf = self::getContainer()->get('security.csrf.token_manager');
            $token = $csrf->getToken(ChangeCartItemSubscriptionTermsAction::CSRF_TOKEN_ID)->getValue();
            $session->save();

            return $token;
        } finally {
            $requestStack->pop();
        }
    }

    /** @return array<string, list<string>> the session's flash bag, by type */
    private function flashes(): array
    {
        $request = $this->client->getRequest();
        self::assertNotNull($request);
        $session = $request->getSession();

        $flashes = [];
        foreach (['success', 'error'] as $type) {
            $messages = $session->getFlashBag()->peek($type);
            if ([] !== $messages) {
                $flashes[$type] = $messages;
            }
        }

        return $flashes;
    }

    /** Adds the product once from its page, as AddingASubscriptionToTheCartTest does, which also starts the session cart. */
    private function addToCartAndGetItem(): OrderItemInterface
    {
        $crawler = $this->client->request('GET', \sprintf('/%s/products/%s', self::LOCALE, $this->product->getSlug()));
        self::assertResponseIsSuccessful();

        $component = $crawler->filter(\sprintf('[data-live-name-value="%s"]', self::COMPONENT));
        self::assertCount(1, $component, 'The product page does not render the add-to-cart component.');

        /** @var array<string, mixed> $props */
        $props = json_decode((string) $component->attr('data-live-props-value'), true, 512, \JSON_THROW_ON_ERROR);
        $updated = [self::FORM . '.cartItem.quantity' => '1'];

        $this->client->request('POST', $component->attr('data-live-url-value') . '/addToCart', [
            'data' => json_encode(['props' => $props, 'updated' => $updated, 'validatedFields' => array_keys($updated)], \JSON_THROW_ON_ERROR),
        ]);
        self::assertLessThan(400, $this->client->getResponse()->getStatusCode());

        $cart = $this->theCart();
        $item = $cart->getItems()->first();
        self::assertInstanceOf(OrderItemInterface::class, $item);

        return $item;
    }

    private function theCart(): OrderInterface
    {
        /** @var RepositoryInterface<OrderInterface> $orders */
        $orders = self::getContainer()->get('sylius.repository.order');
        /** @var list<OrderInterface> $carts */
        $carts = $orders->findBy(['state' => OrderInterface::STATE_CART]);
        self::assertCount(1, $carts);

        return $carts[0];
    }

    /**
     * A second, unrelated cart, never put in this client's session. Framework test resets give every request its
     * own entity manager, so the channel and variant kept since setUp() are foreign to this one: fetched fresh by
     * their natural keys instead of reused, the way a real request would load them.
     */
    private function anotherCartWithALine(): OrderInterface
    {
        /** @var RepositoryInterface<ChannelInterface> $channels */
        $channels = self::getContainer()->get('sylius.repository.channel');
        $channel = $channels->findOneBy(['code' => $this->channel->getCode()]);
        self::assertInstanceOf(ChannelInterface::class, $channel);

        /** @var RepositoryInterface<ProductVariantInterface> $variants */
        $variants = self::getContainer()->get('sylius.repository.product_variant');
        $variant = $variants->findOneBy(['code' => $this->variantCode]);
        self::assertInstanceOf(ProductVariantInterface::class, $variant);

        /** @var FactoryInterface<OrderItemInterface> $itemFactory */
        $itemFactory = self::getContainer()->get('sylius.factory.order_item');
        $item = $itemFactory->createNew();
        $item->setVariant($variant);
        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $item);

        /** @var FactoryInterface<OrderInterface> $orderFactory */
        $orderFactory = self::getContainer()->get('sylius.factory.order');
        $cart = $orderFactory->createNew();
        $cart->setChannel($channel);
        $cart->setCurrencyCode('USD');
        $cart->setLocaleCode(self::LOCALE);
        $cart->addItem($item);

        $this->entityManager()->persist($cart);
        $this->entityManager()->flush();

        return $cart;
    }

    private function reloadItem(OrderItemInterface $item): OrderItemInterface
    {
        $this->entityManager()->clear();

        /** @var RepositoryInterface<OrderItemInterface> $items */
        $items = self::getContainer()->get('sylius.repository.order_item');
        $reloaded = $items->find($item->getId());
        self::assertInstanceOf(OrderItemInterface::class, $reloaded);

        return $reloaded;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }
}
