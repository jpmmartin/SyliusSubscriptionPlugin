<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Prepaid;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\PrepaidDeliveryPlaced;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalPaid;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalUpcoming;
use Sylius\Behat\Context\Setup\ShippingContext;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Core\OrderShippingStates;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Event\EventCollector;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;

/**
 * Coffee at $90.00 a delivery on its monthly plan, charged by blocks of three, subscribed to on
 * 1 January: February and March are delivered without a charge, and April charges the next block.
 * The subscription renews with a shipping method that costs $10.00.
 */
final class PrepaidDeliveriesTest extends LifecycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->coffeeMonthly->setDeliveriesPerCharge(3);
        $this->entityManager()->flush();
        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedCoffeeOrder());

        /** @var ShippingContext $shipping */
        $shipping = self::getContainer()->get('sylius.behat.context.setup.shipping');
        $shipping->storeHasShippingMethodWithFee('DHL', 1000);
        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = self::getContainer()->get('sylius.behat.shared_storage');
        $dhl = $sharedStorage->get('shipping_method');
        self::assertInstanceOf(ShippingMethodInterface::class, $dhl);
        $this->subscription()->setShippingMethod($dhl);
        $this->entityManager()->flush();
        $this->collector()->clear();
    }

    public function testTheSecondDeliveryOfTheBlockIsPlacedWithoutAChargeShippingOrPrice(): void
    {
        $this->renewOn('2027-02-01');

        $subscription = $this->subscription();
        [, $second, $third] = $this->storedCycles($subscription);
        self::assertFalse($second->isCharging());
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $second->getState(), 'Closed as delivered.');
        self::assertCount(0, $second->getAttempts(), 'Nothing was charged.');
        $order = $second->getOrder();
        self::assertNotNull($order);
        self::assertSame([0, 0, 0], [$order->getItemsTotal(), $order->getShippingTotal(), $order->getTotal()]);
        self::assertSame(1, $order->getItems()->first() ? $order->getItems()->first()->getQuantity() : null);
        self::assertSame(OrderPaymentStates::STATE_PAID, $order->getPaymentState());
        self::assertSame(OrderShippingStates::STATE_READY, $order->getShippingState(), 'Prepared and sent like any other order.');
        self::assertSame([], $this->scriptedGateway()->requests());

        self::assertSame(1, $subscription->getPrepaidDeliveriesLeft());
        self::assertSame(2, $this->onlyItemOf($subscription)->getPaidCycles());
        self::assertFalse($third->isCharging(), 'March is delivered too.');
        self::assertEquals(
            [new PrepaidDeliveryPlaced((int) $subscription->getId(), (int) $second->getId(), 2, (int) $order->getId(), 1)],
            $this->collector()->events(PrepaidDeliveryPlaced::class),
        );
        self::assertSame([], $this->collector()->events(RenewalPaid::class), 'No charge to tell the customer about.');
    }

    public function testAfterTheBlocksDeliveriesTheNextCycleChargesTheNextBlockWithItsFirstShipping(): void
    {
        $this->renewOn('2027-02-01', '2027-03-01');

        $subscription = $this->subscription();
        [, , $third, $fourth] = $this->storedCycles($subscription);
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $third->getState());
        self::assertSame(0, $subscription->getPrepaidDeliveriesLeft());
        self::assertTrue($fourth->isCharging());
        self::assertSame('2027-04-01 09:00', $fourth->getScheduledAt()?->format('Y-m-d H:i'));

        $this->renewOn('2027-04-01');

        [, , , $fourth, $fifth] = $this->storedCycles($this->subscription());
        $order = $fourth->getOrder();
        self::assertNotNull($order);
        self::assertSame([27000, 1000], [$order->getItemsTotal(), $order->getShippingTotal()], 'The block, and the shipping of its first delivery.');
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $fourth->getState());
        self::assertSame(2, $this->subscription()->getPrepaidDeliveriesLeft());
        self::assertFalse($fifth->isCharging());
    }

    public function testEachDeliveryCountsAgainstTheMaximumOfCycles(): void
    {
        $this->coffeeMonthly->setMaxCycles(3);
        $this->entityManager()->flush();

        $this->renewOn('2027-02-01', '2027-03-01');

        $subscription = $this->subscription();
        self::assertSame(3, $this->onlyItemOf($subscription)->getPaidCycles());
        self::assertSame(SubscriptionInterface::STATE_COMPLETED, $subscription->getState());
    }

    public function testOnlyTheChargesAreAnnounced(): void
    {
        $this->itIsNow('2027-01-29 09:00');
        $this->runTheCycleCommand();
        self::assertSame([], $this->collector()->events(RenewalUpcoming::class), 'February is a delivery.');

        $this->renewOn('2027-02-01', '2027-03-01');
        $this->itIsNow('2027-03-29 09:00');
        $this->runTheCycleCommand();

        $events = $this->collector()->events(RenewalUpcoming::class);
        self::assertCount(1, $events);
        self::assertInstanceOf(RenewalUpcoming::class, $events[0]);
        self::assertSame('2027-04-01', $events[0]->scheduledAt->format('Y-m-d'));
    }

    private function renewOn(string ...$dates): void
    {
        foreach ($dates as $date) {
            $this->itIsNow($date . ' 09:00');
            $this->runTheCycleCommand();
        }
    }

    private function subscription(): SubscriptionInterface
    {
        $subscriptions = $this->storedSubscriptions();
        self::assertCount(1, $subscriptions);

        return $subscriptions[0];
    }

    private function collector(): EventCollector
    {
        $collector = self::getContainer()->get('jpm_martin_sylius_subscription.test.event_collector');
        self::assertInstanceOf(EventCollector::class, $collector);

        return $collector;
    }
}
