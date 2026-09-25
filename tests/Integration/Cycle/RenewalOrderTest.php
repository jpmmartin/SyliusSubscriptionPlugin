<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Cycle;

use JpmMartin\SyliusSubscriptionPlugin\CommandHandler\ProcessSubscriptionCycleHandler;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Factory\SubscriptionPlanFactoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Order\RenewalOrderPlacerInterface;
use Sylius\Behat\Context\Setup\PromotionContext;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\PromotionInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Order\Model\OrderItemInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;

/**
 * The second cycle of a monthly batch of one Coffee (shipped) and one Tea (not shipped), and of a Tea
 * subscription every two weeks, all activated on 1 January. Before they renew, Coffee's price and the
 * shipping charge go up and an automatic promotion starts: the lines keep their frozen prices, the rest
 * is worked out afresh.
 */
final class RenewalOrderTest extends LifecycleTestCase
{
    private OrderInterface $initialBatchOrder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->itIsNow('2027-01-01 09:00');
        $this->initialBatchOrder = $this->cart();
        $this->addLine($this->initialBatchOrder, $this->coffee, 1, $this->coffeeMonthly);
        $this->addLine($this->initialBatchOrder, $this->tea, 1, $this->teaMonthly);
        $this->placeWithConsent($this->initialBatchOrder);
        $this->pay($this->initialBatchOrder);
        $teaOrder = $this->cart();
        $this->addLine($teaOrder, $this->tea, 3, $this->teaEveryTwoWeeks);
        $this->placeWithConsent($teaOrder);
        $this->pay($teaOrder);

        $channelPricing = $this->coffee->getChannelPricingForChannel($this->channel);
        self::assertInstanceOf(ChannelPricingInterface::class, $channelPricing);
        $channelPricing->setPrice(12000);
        $this->shippingMethod->setConfiguration([(string) $this->channel->getCode() => ['amount' => 500]]);
        /** @var PromotionContext $promotions */
        $promotions = self::getContainer()->get('sylius.behat.context.setup.promotion');
        $promotions->thereIsPromotion('Autumn');
        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = self::getContainer()->get('sylius.behat.shared_storage');
        /** @var PromotionInterface $promotion */
        $promotion = $sharedStorage->get('promotion');
        $promotions->itGivesPercentageDiscountToEveryOrder($promotion, 0.1);
        $this->entityManager()->flush();

        $this->itIsNow('2027-02-01 09:00');
    }

    public function testABatchRenewsWithOneCompletedOrderOfALinePerItemAtTheFrozenPricesWithShippingWorkedOutAfresh(): void
    {
        $subscription = $this->batch();
        $cycle = $this->storedCycles($subscription)[1];

        $order = $this->placeRenewal($cycle);

        self::assertSame(OrderCheckoutStates::STATE_COMPLETED, $order->getCheckoutState());
        self::assertSame(OrderInterface::STATE_NEW, $order->getState());
        self::assertSame(OrderPaymentStates::STATE_AWAITING_PAYMENT, $order->getPaymentState());
        self::assertNotNull($order->getNumber());
        self::assertSame($this->customer->getId(), $order->getCustomer()?->getId());
        self::assertSame($this->channel->getCode(), $order->getChannel()?->getCode());

        self::assertSame([
            ['Coffee', 1, 9000, true],
            ['Tea', 1, 5000, true],
        ], $this->linesOf($order), 'The frozen prices, not Coffee\'s new 120.00.');

        self::assertSame(500, $order->getShippingTotal());
        self::assertSame(-1400, $order->getOrderPromotionTotal());
        self::assertSame(13100, $order->getTotal());
        self::assertCount(1, $order->getShipments());
        self::assertSame($this->shippingMethod->getCode(), $order->getShipments()->first()?->getMethod()?->getCode());

        $payment = $order->getLastPayment();
        self::assertNotNull($payment);
        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
        self::assertSame($this->paymentMethod->getCode(), $payment->getMethod()?->getCode());
        self::assertSame(13100, $payment->getAmount());

        $initialAddress = $this->initialBatchOrder->getShippingAddress();
        self::assertNotNull($initialAddress);
        self::assertSame($initialAddress->getStreet(), $order->getShippingAddress()?->getStreet());
        self::assertSame($initialAddress->getPostcode(), $order->getBillingAddress()?->getPostcode());
        self::assertNotSame($initialAddress->getId(), $order->getShippingAddress()?->getId());

        $cycle = $this->storedCycles($subscription)[1];
        self::assertSame(SubscriptionCycleInterface::STATE_SCHEDULED, $cycle->getState(), 'Moving the cycle on is left to the caller.');
        self::assertSame($order->getId(), $cycle->getOrder()?->getId());
        self::assertSame([['Coffee', 1, 9000, null], ['Tea', 1, 5000, null]], $this->recordedItemsOf($cycle));
        self::assertCount(2, $this->storedSubscriptions(), 'The renewal started a subscription of its own.');
    }

    public function testASubscriptionWhoseAddressesWereChangedRenewsToThemAndNotToItsLastOrders(): void
    {
        /** @var FactoryInterface<AddressInterface> $addresses */
        $addresses = self::getContainer()->get('sylius.factory.address');
        $shipping = $addresses->createNew();
        $shipping->setFirstName('John');
        $shipping->setLastName('Doe');
        $shipping->setStreet('Elm Street 13');
        $shipping->setCity('Springwood');
        $shipping->setPostcode('43210');
        $shipping->setCountryCode('US');
        $billing = $addresses->createNew();
        $billing->setFirstName('John');
        $billing->setLastName('Doe');
        $billing->setStreet('Office Road 1');
        $billing->setCity('Springwood');
        $billing->setPostcode('43211');
        $billing->setCountryCode('US');
        $batch = $this->batch();
        $batch->setShippingAddress($shipping);
        $batch->setBillingAddress($billing);
        $this->entityManager()->flush();

        [, $cycle] = $this->storedCycles($this->batch());
        $order = $this->placeRenewal($cycle);

        self::assertSame('Elm Street 13', $order->getShippingAddress()?->getStreet());
        self::assertSame('Office Road 1', $order->getBillingAddress()?->getStreet());
        self::assertNotSame($this->batch()->getShippingAddress()?->getId(), $order->getShippingAddress()?->getId(), 'The order has its own copy.');
    }

    public function testASubscriptionThatShipsNothingRenewsWithAnOrderWithoutShipping(): void
    {
        $subscription = $this->subscriptionsByPlan()['TEA_EVERY_TWO_WEEKS'];
        $cycle = $this->storedCycles($subscription)[1];

        $order = $this->placeRenewal($cycle);

        self::assertSame(OrderCheckoutStates::STATE_COMPLETED, $order->getCheckoutState());
        self::assertCount(0, $order->getShipments());
        self::assertSame(0, $order->getShippingTotal());
        self::assertSame([['Tea', 3, 5000, true]], $this->linesOf($order));
        self::assertSame(13500, $order->getTotal(), 'Three teas at 50.00, less the 10% promotion, and nothing else.');

        $payment = $order->getLastPayment();
        self::assertNotNull($payment);
        self::assertSame($this->paymentMethod->getCode(), $payment->getMethod()?->getCode());
        self::assertSame(13500, $payment->getAmount());
    }

    public function testAnItemOutOfStockIsSkippedInThisCycleAndStaysInTheSubscription(): void
    {
        $this->tea->setTracked(true);
        $this->tea->setOnHand(0);
        $this->tea->setOnHold(0);
        $this->entityManager()->flush();
        $subscription = $this->batch();

        $order = $this->placeRenewal($this->storedCycles($subscription)[1]);

        self::assertSame([['Coffee', 1, 9000, true]], $this->linesOf($order));
        self::assertSame(8600, $order->getTotal(), 'Only Coffee is charged: 90.00, less the promotion, plus shipping.');
        self::assertSame(
            [['Coffee', 1, 9000, null], ['Tea', 1, 5000, SubscriptionCycleItemInterface::SKIPPED_OUT_OF_STOCK]],
            $this->recordedItemsOf($this->storedCycles($subscription)[1]),
        );
        self::assertCount(2, $this->batch()->getItems());
    }

    public function testAnItemWhoseVariantIsDisabledIsSkippedAndAnOrderLeftWithNothingToShipHasNoShipping(): void
    {
        $this->coffee->setEnabled(false);
        $this->entityManager()->flush();
        $subscription = $this->batch();

        $order = $this->placeRenewal($this->storedCycles($subscription)[1]);

        self::assertSame([['Tea', 1, 5000, true]], $this->linesOf($order));
        self::assertCount(0, $order->getShipments());
        self::assertSame(
            [['Coffee', 1, 9000, SubscriptionCycleItemInterface::SKIPPED_DISABLED], ['Tea', 1, 5000, null]],
            $this->recordedItemsOf($this->storedCycles($subscription)[1]),
        );
    }

    public function testAnItemWhoseProductIsDisabledOrNoLongerInTheChannelIsSkipped(): void
    {
        $coffeeProduct = $this->coffee->getProduct();
        self::assertInstanceOf(ProductInterface::class, $coffeeProduct);
        $coffeeProduct->setEnabled(false);
        $teaProduct = $this->tea->getProduct();
        self::assertInstanceOf(ProductInterface::class, $teaProduct);
        $teaProduct->removeChannel($this->channel);
        $this->entityManager()->flush();
        $subscription = $this->batch();

        $cycle = $this->storedCycles($subscription)[1];
        $placer = self::getContainer()->get(RenewalOrderPlacerInterface::class);
        self::assertInstanceOf(RenewalOrderPlacerInterface::class, $placer);

        self::assertNull($placer->place($cycle), 'Nothing left to renew, so no order.');
        $this->entityManager()->flush();

        $cycle = $this->storedCycles($subscription)[1];
        self::assertNull($cycle->getOrder());
        self::assertSame(
            [['Coffee', 1, 9000, SubscriptionCycleItemInterface::SKIPPED_DISABLED], ['Tea', 1, 5000, SubscriptionCycleItemInterface::SKIPPED_NOT_IN_CHANNEL]],
            $this->recordedItemsOf($cycle),
        );
    }

    public function testTwoItemsOfTheSameVariantNeverTakeMoreStockThanThereIs(): void
    {
        /** @var SubscriptionPlanFactoryInterface $planFactory */
        $planFactory = self::getContainer()->get('jpm_martin_sylius_subscription.factory.subscription_plan');
        $bulk = $planFactory->createForVariant($this->coffee);
        $bulk->setCode('COFFEE_MONTHLY_BULK');
        $bulk->setName('Coffee monthly, in bulk');
        $bulk->setIntervalCount(1);
        $bulk->setIntervalUnit(SubscriptionIntervalUnit::Month);
        $bulk->setDiscountPercentage(20);
        $this->entityManager()->persist($bulk);
        $this->itIsNow('2027-01-10 09:00');
        $order = $this->cart();
        $this->addLine($order, $this->coffee, 2, $this->coffeeMonthly);
        $this->addLine($order, $this->coffee, 2, $bulk);
        $this->placeWithConsent($order);
        $this->pay($order);
        $this->coffee->setTracked(true);
        $this->coffee->setOnHand(3);
        $this->coffee->setOnHold(0);
        $this->entityManager()->flush();
        $subscription = $this->subscriptionsByPlan()['COFFEE_MONTHLY+COFFEE_MONTHLY_BULK'];

        $renewal = $this->placeRenewal($this->storedCycles($subscription)[1]);

        // Frozen at Coffee's new 120.00: 10% off on one plan, 20% off on the other.
        self::assertSame([['Coffee', 2, 10800, true]], $this->linesOf($renewal), 'Two lines of the same variant are not merged, and three bags cannot cover four.');
        self::assertSame(
            [['Coffee', 2, 10800, null], ['Coffee', 2, 9600, SubscriptionCycleItemInterface::SKIPPED_OUT_OF_STOCK]],
            $this->recordedItemsOf($this->storedCycles($subscription)[1]),
        );
    }

    public function testACycleWithNothingAvailableFailsWithoutAnOrderAndTheNextOneIsScheduled(): void
    {
        $this->coffee->setEnabled(false);
        $this->tea->setEnabled(false);
        $this->entityManager()->flush();

        $this->runTheCycleCommand();

        $subscription = $this->batch();
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState());
        self::assertSame(1, $subscription->getConsecutiveFailedCycles());
        [, $failed, $next] = $this->storedCycles($subscription);
        self::assertSame(SubscriptionCycleInterface::STATE_FAILED, $failed->getState());
        self::assertNull($failed->getOrder());
        self::assertCount(0, $failed->getAttempts());
        self::assertSame(ProcessSubscriptionCycleHandler::NOTHING_TO_RENEW, $failed->getCancellationReason());
        self::assertSame(
            [['Coffee', 1, 9000, SubscriptionCycleItemInterface::SKIPPED_DISABLED], ['Tea', 1, 5000, SubscriptionCycleItemInterface::SKIPPED_DISABLED]],
            $this->recordedItemsOf($failed),
        );
        self::assertSame(3, $next->getNumber());
        self::assertSame(SubscriptionCycleInterface::STATE_SCHEDULED, $next->getState());
        self::assertSame('2027-03-01 09:00', $next->getScheduledAt()?->format('Y-m-d H:i'));
    }

    private function batch(): SubscriptionInterface
    {
        return $this->subscriptionsByPlan()['COFFEE_MONTHLY+TEA_MONTHLY'];
    }

    private function placeRenewal(SubscriptionCycleInterface $cycle): OrderInterface
    {
        $placer = self::getContainer()->get(RenewalOrderPlacerInterface::class);
        self::assertInstanceOf(RenewalOrderPlacerInterface::class, $placer);

        $order = $placer->place($cycle);
        self::assertNotNull($order);
        $this->entityManager()->flush();
        $this->entityManager()->refresh($order);

        return $order;
    }

    /** @return list<array{string|null, int, int, bool}> product, quantity, unit price and whether it is immutable, by line */
    private function linesOf(OrderInterface $order): array
    {
        return array_values(array_map(
            static fn (OrderItemInterface $line): array => [$line->getProductName(), $line->getQuantity(), $line->getUnitPrice(), $line->isImmutable()],
            $order->getItems()->toArray(),
        ));
    }

    /** @return list<array{string|null, int, int, string|null}> product, quantity, unit price and skipped reason, by item */
    private function recordedItemsOf(SubscriptionCycleInterface $cycle): array
    {
        return array_values(array_map(
            static fn (SubscriptionCycleItemInterface $recorded): array => [
                $recorded->getSubscriptionItem()?->getProductVariant()?->getProduct()?->getName(),
                $recorded->getQuantity(),
                $recorded->getUnitPrice(),
                $recorded->getSkippedReason(),
            ],
            $cycle->getItems()->toArray(),
        ));
    }
}
