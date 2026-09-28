<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Functional\Admin;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use Sylius\Behat\Context\Hook\DoctrineORMContext;
use Sylius\Behat\Context\Setup\AdminUserContext;
use Sylius\Behat\Context\Setup\ChannelContext;
use Sylius\Behat\Context\Setup\ProductContext;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup\SubscriptionFrequencyContext;

/**
 * "Update subscription prices" from a store frequency and from a variant, as an administrator: the
 * preview answers, and confirming it needs the form's token. Updating a plan's subscriptions is a
 * scenario of features/admin/managing_subscriptions.feature.
 */
final class UpdatingSubscriptionPricesTest extends WebTestCase
{
    private KernelBrowser $client;

    private int $frequencyId;

    private int $variantId;

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
        /** @var SubscriptionFrequencyContext $frequencies */
        $frequencies = $container->get('jpm_martin_sylius_subscription.behat.context.setup.subscription_frequency');
        $frequencies->theStoreOffersASubscriptionFrequency('MONTHLY', '1', 'month', '5');

        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = $container->get('sylius.behat.shared_storage');
        /** @var ProductInterface $product */
        $product = $sharedStorage->get('product');
        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariantInterface::class, $variant);
        $this->variantId = (int) $variant->getId();
        $frequency = $sharedStorage->get('subscription_frequency');
        self::assertInstanceOf(SubscriptionFrequencyInterface::class, $frequency);
        $this->frequencyId = (int) $frequency->getId();

        /** @var AdminUserContext $admins */
        $admins = $container->get('sylius.behat.context.setup.admin_user');
        $admins->thereIsAnAdministratorIdentifiedBy('admin@example.com');
        $admin = $sharedStorage->get('administrator');
        self::assertInstanceOf(AdminUserInterface::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    public function testTheFrequencyAndTheVariantPagesLeadToTheirPreview(): void
    {
        foreach ([
            \sprintf('/admin/subscription-frequencies/%d/edit', $this->frequencyId) => \sprintf('/admin/subscription-price-updates/frequency/%d', $this->frequencyId),
            \sprintf('/admin/products/%d/variants/%d/edit', $this->productIdOfTheVariant(), $this->variantId) => \sprintf('/admin/subscription-price-updates/variant/%d', $this->variantId),
        ] as $page => $preview) {
            $crawler = $this->client->request('GET', $page);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $page);
            self::assertSame($preview, $crawler->filter('[data-test-update-subscription-prices]')->attr('href'), $page);

            $crawler = $this->client->request('GET', $preview);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $preview);
            self::assertCount(1, $crawler->filter('[data-test-no-subscriptions-to-update]'), 'No subscription yet.');
        }
    }

    public function testConfirmingWithoutTheFormsTokenIsRefused(): void
    {
        $this->client->request('POST', \sprintf('/admin/subscription-price-updates/variant/%d', $this->variantId), ['_csrf_token' => 'forged']);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    private function productIdOfTheVariant(): int
    {
        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = self::getContainer()->get('sylius.behat.shared_storage');
        /** @var ProductInterface $product */
        $product = $sharedStorage->get('product');

        return (int) $product->getId();
    }
}
