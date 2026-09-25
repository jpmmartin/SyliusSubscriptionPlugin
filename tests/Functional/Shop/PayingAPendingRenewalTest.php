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
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Domain\ProcessingRenewalsContext;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup\SubscriptionContext;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup\SubscriptionPlanContext;

/**
 * A renewal whose charge was declined, with a retry still to come, paid by its customer on Sylius's own
 * order payment page, through the same payment requests the checkout uses: the page takes renewal
 * orders, and paying one charges its cycle as the plugin's own charge would.
 *
 * The store is set up with the same Behat setup services the scenarios use.
 */
final class PayingAPendingRenewalTest extends WebTestCase
{
    private const LOCALE = 'en_US';

    private KernelBrowser $client;

    private SubscriptionInterface $subscription;

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
        $this->subscription = $subscription;

        $subscriptions->theTestGatewayWillDeclineTheNextCharge('Insufficient funds.');
        $this->renewalsAreProcessed('2027-02-01 09:00');

        $this->client->loginUser($me, 'shop');
    }

    protected function tearDown(): void
    {
        /** @var CalendarHookContext $calendar */
        $calendar = self::getContainer()->get('sylius.behat.context.hook.calendar');
        $calendar->deleteTemporaryDate();

        parent::tearDown();
    }

    public function testTheCustomerPaysTheDeclinedRenewalOnTheOrderPaymentPageAndItsCycleIsCharged(): void
    {
        $cycle = $this->cycle(2);
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $cycle->getState());
        self::assertNotNull($cycle->getNextAttemptAt(), 'A retry is still to come.');
        $order = $cycle->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);
        $tokenValue = $order->getTokenValue();
        self::assertNotNull($tokenValue, 'The renewal order has a token.');

        $crawler = $this->client->request('GET', \sprintf('/%s/order/%s', self::LOCALE, $tokenValue));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $payButton = $crawler->filter('[data-test-pay-link]');
        self::assertCount(1, $payButton, 'The page offers to pay the order.');
        self::assertNull($payButton->attr('disabled'));

        $this->client->followRedirects();
        $this->client->submit($payButton->form());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $cycle = $this->cycle(2);
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $cycle->getState());
        self::assertNull($cycle->getNextAttemptAt(), 'The retry is not made.');
        $order = $cycle->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);
        self::assertSame(OrderPaymentStates::STATE_PAID, $order->getPaymentState());
        self::assertSame(
            [PaymentInterface::STATE_FAILED, PaymentInterface::STATE_COMPLETED],
            array_values(array_map(static fn (PaymentInterface $payment): string => $payment->getState(), $order->getPayments()->toArray())),
            'The declined payment, then the one the customer paid.',
        );
        $next = $this->cycle(3);
        self::assertSame(SubscriptionCycleInterface::STATE_SCHEDULED, $next->getState());
        self::assertEquals(new \DateTimeImmutable('2027-03-01 09:00'), $next->getScheduledAt());
    }

    private function renewalsAreProcessed(string $dateTime): void
    {
        /** @var ProcessingRenewalsContext $renewals */
        $renewals = self::getContainer()->get('jpm_martin_sylius_subscription.behat.context.domain.processing_renewals');
        $renewals->theRenewalsDueOnAreProcessed($dateTime);
    }

    private function cycle(int $number): SubscriptionCycleInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $entityManager->clear();
        $cycle = $entityManager->getRepository(SubscriptionCycleInterface::class)->findOneBy(['subscription' => $this->subscription->getId(), 'number' => $number]);
        self::assertInstanceOf(SubscriptionCycleInterface::class, $cycle, \sprintf('The subscription has no cycle %d.', $number));

        return $cycle;
    }
}
