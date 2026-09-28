<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Functional\Api;

use Doctrine\DBAL\Connection;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionEventInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionPaused;
use Sylius\Behat\Context\Setup\ProductContext;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Domain\ProcessingRenewalsContext;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup\SubscriptionContext;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup\SubscriptionPlanContext;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Event\EventCollector;

/** The shop API's actions on the customer's subscriptions: PATCH /api/v2/shop/subscriptions/{id}/<action>. */
final class ManagingMySubscriptionsTest extends ShopSubscriptionApiTestCase
{
    public function testTheCustomerPausesAndResumesTheirSubscription(): void
    {
        $this->signIn();

        $this->request('PATCH', $this->uriOf($this->mySubscription, '/pause'), []);
        self::assertResponseIsSuccessful();
        $response = $this->responseJson();
        self::assertSame('paused', $response['state']);
        self::assertIsArray($response['actions']);
        self::assertContains('resume', $response['actions']);
        self::assertSame(SubscriptionInterface::STATE_PAUSED, $this->stored($this->mySubscription)->getState());

        $this->request('PATCH', $this->uriOf($this->mySubscription, '/resume'), []);
        self::assertResponseIsSuccessful();
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $this->stored($this->mySubscription)->getState());
    }

    public function testTheCustomerCancelsTheirSubscription(): void
    {
        $this->signIn();
        $this->request('PATCH', $this->uriOf($this->mySubscription, '/cancel'), []);

        self::assertResponseIsSuccessful();
        self::assertSame(SubscriptionInterface::STATE_CANCELLED, $this->stored($this->mySubscription)->getState());
    }

    public function testTheCustomerSkipsTheNextRenewal(): void
    {
        $this->signIn();
        $this->request('PATCH', $this->uriOf($this->mySubscription, '/skip-renewal'), []);

        self::assertResponseIsSuccessful();
        $cycles = array_values($this->stored($this->mySubscription)->getCycles()->toArray());
        self::assertTrue($cycles[1]->isSkipped());
        self::assertSame('2027-03-01', $cycles[2]->getScheduledAt()?->format('Y-m-d'));
    }

    public function testAnActionTheAccountWouldNotOfferIsRefusedWithItsReasonAndChangesNothing(): void
    {
        $this->theNextRenewalIsDeclined();
        $this->signIn();

        $this->request('PATCH', $this->uriOf($this->mySubscription, '/skip-renewal'), []);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['The next renewal of this subscription cannot be skipped now.'], $this->violationMessages());
        $cycles = array_values($this->stored($this->mySubscription)->getCycles()->toArray());
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $cycles[1]->getState(), 'Its order already waits for payment.');
        self::assertFalse($cycles[1]->isSkipped());
    }

    public function testWithinItsMinimumCommitmentItCanBeNeitherCancelledNorPaused(): void
    {
        // Its item commits it to six paid cycles, and only the first is paid.
        $this->storedSubscriptionIs(static function (SubscriptionInterface $subscription): void {
            self::itemOf($subscription)->setCommitmentCycles(6);
        });
        $this->signIn();

        $this->request('PATCH', $this->uriOf($this->mySubscription, '/cancel'), []);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['This subscription cannot be cancelled now.'], $this->violationMessages());
        $this->request('PATCH', $this->uriOf($this->mySubscription, '/pause'), []);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['This subscription cannot be paused now.'], $this->violationMessages());

        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $this->stored($this->mySubscription)->getState());
        $this->request('GET', $this->uriOf($this->mySubscription));
        $response = $this->responseJson();
        self::assertSame(5, $response['remainingCommitmentCycles']);
        self::assertIsArray($response['actions']);
        self::assertNotContains('cancel', $response['actions']);
        self::assertNotContains('pause', $response['actions']);
    }

    public function testWithDeliveriesPaidForItIsCancelledAfterTheLastOfThem(): void
    {
        $this->storedSubscriptionIs(static function (SubscriptionInterface $subscription): void {
            // January's charge paid for February and March too.
            $subscription->setPrepaidDeliveriesLeft(2);
            self::cycleOf($subscription, 2)->setCharging(false);
        });
        $this->signIn();

        $this->request('PATCH', $this->uriOf($this->mySubscription, '/cancel'), []);

        self::assertResponseIsSuccessful();
        $response = $this->responseJson();
        self::assertSame(['active', true], [$response['state'], $response['cancelsAfterPrepaidDeliveries']]);
        self::assertIsArray($response['actions']);
        self::assertNotContains('cancel', $response['actions'], 'It is already being cancelled.');
        $subscription = $this->stored($this->mySubscription);
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState());
        self::assertTrue($subscription->cancelsAfterPrepaidDeliveries());

        $this->request('PATCH', $this->uriOf($this->mySubscription, '/cancel'), []);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnotherCustomersSubscriptionCannotBeActedOn(): void
    {
        $this->signIn();
        $this->request('PATCH', $this->uriOf($this->theirSubscription, '/pause'), []);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $this->stored($this->theirSubscription)->getState());
    }

    public function testTheCustomerChangesTheFrequencyToOneOffered(): void
    {
        $this->signIn();

        $this->request('PATCH', $this->uriOf($this->mySubscription, '/frequency'), ['intervalCount' => 2, 'intervalUnit' => 'week']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['This frequency is not offered for this subscription.'], $this->violationMessages());

        $this->request('PATCH', $this->uriOf($this->mySubscription, '/frequency'), ['intervalCount' => 3, 'intervalUnit' => 'month']);
        self::assertResponseIsSuccessful();
        $subscription = $this->stored($this->mySubscription);
        self::assertSame([3, SubscriptionIntervalUnit::Month], [$subscription->getDeliveryIntervalCount(), $subscription->getDeliveryIntervalUnit()]);
        self::assertSame('COFFEE_QUARTERLY', $subscription->getItems()->first() ? $subscription->getItems()->first()->getPlan()?->getCode() : null);
    }

    public function testTheCustomerChangesTheAddressItRenewsTo(): void
    {
        $this->signIn();

        $this->request('PATCH', $this->uriOf($this->mySubscription, '/address'), ['shippingAddress' => ['firstName' => 'Jon']]);
        self::assertResponseStatusCodeSame(422, 'An address without its street, city, postcode or country.');

        $this->request('PATCH', $this->uriOf($this->mySubscription, '/address'), ['shippingAddress' => [
            'firstName' => 'Jon',
            'lastName' => 'Snow',
            'street' => 'Castle Black',
            'city' => 'The Wall',
            'postcode' => '10001',
            'countryCode' => 'US',
        ]]);
        self::assertResponseIsSuccessful();
        $response = $this->responseJson();
        self::assertIsArray($response['shippingAddress']);
        self::assertSame('The Wall', $response['shippingAddress']['city']);
        self::assertSame('The Wall', $this->stored($this->mySubscription)->getShippingAddress()?->getCity());
    }

    public function testAPostcodeMayBeSentAsANumber(): void
    {
        $this->signIn();

        $this->request('PATCH', $this->uriOf($this->mySubscription, '/address'), ['shippingAddress' => [
            'firstName' => 'Jon',
            'lastName' => 'Snow',
            'street' => 'Castle Black',
            'city' => 'The Wall',
            'postcode' => 10001,
            'countryCode' => 'US',
        ]]);

        self::assertResponseIsSuccessful();
        self::assertSame('10001', $this->stored($this->mySubscription)->getShippingAddress()?->getPostcode());
    }

    public function testAnAddressFieldThatIsNotTextIsReportedAsMissing(): void
    {
        $this->signIn();

        $this->request('PATCH', $this->uriOf($this->mySubscription, '/address'), ['shippingAddress' => [
            'firstName' => 'Jon',
            'lastName' => 'Snow',
            'street' => ['Castle Black'],
            'city' => 'The Wall',
            'postcode' => '10001',
            'countryCode' => 'US',
        ]]);

        self::assertResponseStatusCodeSame(422);
        $violations = $this->responseJson()['violations'] ?? null;
        self::assertIsArray($violations);
        self::assertSame(['shippingAddress.street'], array_column($violations, 'propertyPath'));
    }

    public function testRaisingAQuantityNeedsTheAcceptedVersionOfTheConsent(): void
    {
        $this->signIn();
        $itemId = $this->mySubscription->getItems()->first() ? $this->mySubscription->getItems()->first()->getId() : null;

        $this->request('PATCH', $this->uriOf($this->mySubscription, '/items'), ['items' => [['id' => $itemId, 'quantity' => 2]]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['These changes raise what each renewal costs: accept the recurring charges of version 1 to save them.'], $this->violationMessages());
        self::assertSame(1, $this->stored($this->mySubscription)->getItems()->first() ? $this->stored($this->mySubscription)->getItems()->first()->getQuantity() : null, 'Nothing was saved.');

        $this->request('PATCH', $this->uriOf($this->mySubscription, '/items'), ['items' => [['id' => $itemId, 'quantity' => 2]], 'acceptedConsentVersion' => '1']);
        self::assertResponseIsSuccessful();
        $subscription = $this->stored($this->mySubscription);
        self::assertSame(2, $subscription->getItems()->first() ? $subscription->getItems()->first()->getQuantity() : null);
        self::assertSame('1', $subscription->getConsentVersion());
    }

    public function testAQuantityIsAWholeNumberFromOneToTheHighestACartLineAllows(): void
    {
        $tea = $this->aProductOnAMonthlyPlan('Tea', 1000, 'TEA_MONTHLY');
        $this->signIn();
        $itemId = self::itemOf($this->mySubscription)->getId();
        $message = 'The quantity must be a whole number between 1 and 9999.';

        foreach (['2', 0, 10000, 1.5] as $quantity) {
            $this->request('PATCH', $this->uriOf($this->mySubscription, '/items'), ['items' => [['id' => $itemId, 'quantity' => $quantity]], 'acceptedConsentVersion' => '1']);
            self::assertResponseStatusCodeSame(422, \sprintf('A quantity of %s.', var_export($quantity, true)));
            self::assertSame([$message], $this->violationMessages());
        }
        $this->request('PATCH', $this->uriOf($this->mySubscription, '/items'), ['items' => ['not an item']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['This item of the subscription cannot be changed.'], $this->violationMessages());
        self::assertSame(1, self::itemOf($this->stored($this->mySubscription))->getQuantity(), 'Nothing was saved.');

        $this->request('PATCH', $this->uriOf($this->mySubscription, '/add-item'), ['productVariant' => $tea->getCode(), 'quantity' => 0, 'acceptedConsentVersion' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([$message], $this->violationMessages());
        self::assertCount(1, $this->stored($this->mySubscription)->getItems());
    }

    public function testTheCustomerAddsAProduct(): void
    {
        $tea = $this->aProductOnAMonthlyPlan('Tea', 1000, 'TEA_MONTHLY');
        $this->signIn();

        $this->request('PATCH', $this->uriOf($this->mySubscription, '/add-item'), ['productVariant' => $tea->getCode(), 'quantity' => 2, 'acceptedConsentVersion' => '1']);

        self::assertResponseIsSuccessful();
        $response = $this->responseJson();
        self::assertIsArray($response['items']);
        self::assertCount(2, $response['items']);
        self::assertCount(2, $this->stored($this->mySubscription)->getItems());
    }

    public function testTheCustomerAcceptsAPriceIncreaseAndGetsNothingToAcceptOtherwise(): void
    {
        $this->signIn();
        $this->request('PATCH', $this->uriOf($this->mySubscription, '/accept-price-increase'), []);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['No price increase of this subscription awaits your acceptance.'], $this->violationMessages());

        $this->anIncreaseAwaitsAcceptance();
        $this->request('PATCH', $this->uriOf($this->mySubscription, '/accept-price-increase'), []);

        self::assertResponseIsSuccessful();
        self::assertNotNull($this->stored($this->mySubscription)->getPriceIncreaseAcceptedAt());
        $response = $this->responseJson();
        self::assertIsArray($response['actions']);
        self::assertNotContains('accept_price_increase', $response['actions']);
    }

    public function testTheLinkToPayARenewalWhoseChargeWasDeclined(): void
    {
        $this->signIn();
        $this->request('GET', $this->uriOf($this->mySubscription, '/renewal-payment-link'));
        self::assertResponseStatusCodeSame(404, 'No renewal waits for its customer.');

        $this->theNextRenewalIsDeclined();
        $this->request('GET', $this->uriOf($this->mySubscription, '/renewal-payment-link'));

        self::assertResponseIsSuccessful();
        $order = array_values($this->stored($this->mySubscription)->getCycles()->toArray())[1]->getOrder();
        self::assertNotNull($order);
        $response = $this->responseJson();
        self::assertIsString($response['url']);
        self::assertMatchesRegularExpression(\sprintf('#^https?://[^/]+/en_US/order/%s$#', preg_quote((string) $order->getTokenValue(), '#')), $response['url']);
    }

    public function testTheLinksToRecoverAndToChangeTheCard(): void
    {
        $this->signIn();

        $this->request('GET', $this->uriOf($this->mySubscription, '/recovery-link'));
        self::assertResponseStatusCodeSame(404, 'It is not suspended.');

        $this->request('GET', $this->uriOf($this->mySubscription, '/card-update-link'));
        self::assertResponseIsSuccessful();
        self::assertSame(\sprintf('https://gateway.example.com/cards/update?subscription=%d', $this->mySubscription->getId()), $this->responseJson()['url']);
    }

    public function testTheEventsOfAnActionAreDeliveredOnceItIsStored(): void
    {
        $this->client->disableReboot();
        $this->signIn();
        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $collector = self::getContainer()->get('jpm_martin_sylius_subscription.test.event_collector');
        self::assertInstanceOf(EventCollector::class, $collector);
        $seen = [];
        $collector->onReceive(function (SubscriptionEventInterface $event) use ($connection, &$seen): void {
            if ($event instanceof SubscriptionPaused) {
                $seen = [
                    $connection->isTransactionActive(),
                    $connection->fetchOne('SELECT state FROM jpm_martin_sylius_subscription WHERE id = ?', [$event->subscriptionId]),
                ];
            }
        });

        $this->request('PATCH', $this->uriOf($this->mySubscription, '/pause'), []);

        self::assertResponseIsSuccessful();
        self::assertSame([false, 'paused'], $seen, 'Delivered after the transaction, with the subscription already paused.');
    }

    private function theNextRenewalIsDeclined(): void
    {
        /** @var SubscriptionContext $subscriptions */
        $subscriptions = self::getContainer()->get('jpm_martin_sylius_subscription.behat.context.setup.subscription');
        $subscriptions->theTestGatewayWillDeclineTheNextCharge('Insufficient funds.');
        /** @var ProcessingRenewalsContext $renewals */
        $renewals = self::getContainer()->get('jpm_martin_sylius_subscription.behat.context.domain.processing_renewals');
        $renewals->theRenewalsDueOnAreProcessed('2027-02-01 09:00');
    }

    private function anIncreaseAwaitsAcceptance(): void
    {
        $container = self::getContainer();
        // Loaded again: the requests before rebooted the kernel, and its entity manager.
        $subscription = $this->stored($this->mySubscription);
        $variant = $subscription->getItems()->first() ? $subscription->getItems()->first()->getProductVariant() : null;
        self::assertNotNull($variant);
        $channel = $subscription->getChannel();
        self::assertNotNull($channel);
        $variant->getChannelPricingForChannel($channel)?->setPrice(2200);
        $container->get('doctrine.orm.entity_manager')->flush();
        /** @var \Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup\PriceUpdateContext $priceUpdates */
        $priceUpdates = $container->get('jpm_martin_sylius_subscription.behat.context.setup.price_update');
        $priceUpdates->theStoreRequiresCustomersToAcceptAPriceIncrease();
        $priceUpdates->thePricesOfTheSubscriptionsOnThePlanHaveBeenUpdated('COFFEE_MONTHLY');
    }

    private function aProductOnAMonthlyPlan(string $name, int $price, string $planCode): ProductVariantInterface
    {
        $container = self::getContainer();
        /** @var ProductContext $products */
        $products = $container->get('sylius.behat.context.setup.product');
        $products->storeHasAProductPricedAt($name, $price);
        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = $container->get('sylius.behat.shared_storage');
        /** @var ProductInterface $product */
        $product = $sharedStorage->get('product');
        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariantInterface::class, $variant);
        /** @var SubscriptionPlanContext $plans */
        $plans = $container->get('jpm_martin_sylius_subscription.behat.context.setup.subscription_plan');
        $plans->theVariantOffersASubscriptionPlan($variant, $planCode, '1', 'month');

        return $variant;
    }

    protected function tearDown(): void
    {
        $priceUpdates = self::getContainer()->get('jpm_martin_sylius_subscription.behat.context.setup.price_update');
        if ($priceUpdates instanceof \Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup\PriceUpdateContext) {
            $priceUpdates->forgetTheAcceptanceSwitch();
        }

        parent::tearDown();
    }
}
