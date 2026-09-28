<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Functional\Admin;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
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
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup\SubscriptionPlanContext;

/**
 * The introductory price in the admin forms of a plan and a frequency: an existing one shows as the
 * choice it was made with, and choosing none clears its discount. Creating a plan with one is a
 * scenario of features/admin/managing_subscription_plans.feature.
 */
final class IntroductoryPriceFormTest extends WebTestCase
{
    private KernelBrowser $client;

    private SubscriptionPlanInterface $plan;

    private SubscriptionFrequencyInterface $frequency;

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
        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariantInterface::class, $variant);

        /** @var SubscriptionPlanContext $plans */
        $plans = $container->get('jpm_martin_sylius_subscription.behat.context.setup.subscription_plan');
        $plans->theVariantOffersASubscriptionPlan($variant, 'COFFEE_MONTHLY', '1', 'month', '10');
        $plans->theSubscriptionPlanHasAnIntroductoryDiscount('COFFEE_MONTHLY', '50', '3');
        $plan = $sharedStorage->get('subscription_plan');
        self::assertInstanceOf(SubscriptionPlanInterface::class, $plan);
        $this->plan = $plan;

        /** @var SubscriptionFrequencyContext $frequencies */
        $frequencies = $container->get('jpm_martin_sylius_subscription.behat.context.setup.subscription_frequency');
        $frequencies->theStoreOffersASubscriptionFrequency('MONTHLY', '1', 'month', '5');
        $frequency = $sharedStorage->get('subscription_frequency');
        self::assertInstanceOf(SubscriptionFrequencyInterface::class, $frequency);
        $frequency->setIntroductoryDiscountPercentage(20);
        $this->entityManager()->flush();
        $this->frequency = $frequency;

        /** @var AdminUserContext $admins */
        $admins = $container->get('sylius.behat.context.setup.admin_user');
        $admins->thereIsAnAdministratorIdentifiedBy('admin@example.com');
        $admin = $sharedStorage->get('administrator');
        self::assertInstanceOf(AdminUserInterface::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    public function testAPlanShowsTheChoiceItsIntroductoryPriceWasMadeWith(): void
    {
        $variant = $this->plan->getProductVariant();
        self::assertNotNull($variant);
        $crawler = $this->client->request('GET', \sprintf('/admin/products/%d/variants/%d/subscription-plans/%d/edit', $variant->getProduct()?->getId(), $variant->getId(), $this->plan->getId()));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $form = $crawler->filter('form[name="jpm_martin_sylius_subscription_subscription_plan"]')->form();
        self::assertSame('first_cycles', $form['jpm_martin_sylius_subscription_subscription_plan[introductoryPrice]']->getValue());
        self::assertSame('50', $form['jpm_martin_sylius_subscription_subscription_plan[introductoryDiscountPercentage]']->getValue());
        self::assertSame('3', $form['jpm_martin_sylius_subscription_subscription_plan[introductoryCycles]']->getValue());
    }

    public function testChoosingNoneClearsTheIntroductoryDiscountOfAFrequency(): void
    {
        $crawler = $this->client->request('GET', \sprintf('/admin/subscription-frequencies/%d/edit', $this->frequency->getId()));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $crawler->filter('form[name="jpm_martin_sylius_subscription_subscription_frequency"]')->form();
        self::assertSame('first_order', $form['jpm_martin_sylius_subscription_subscription_frequency[introductoryPrice]']->getValue());

        $form['jpm_martin_sylius_subscription_subscription_frequency[introductoryPrice]']->setValue('none');
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        $this->entityManager()->clear();
        $frequency = $this->entityManager()->find($this->frequency::class, $this->frequency->getId());
        self::assertInstanceOf(SubscriptionFrequencyInterface::class, $frequency);
        self::assertNull($frequency->getIntroductoryDiscountPercentage());
        self::assertSame(1, $frequency->getIntroductoryCycles());
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }
}
