<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;

final class CreatingSubscriptionsTest extends LifecycleTestCase
{
    public function testTwoLinesOnTheSameIntervalStartOneSubscriptionWithAnItemPerLine(): void
    {
        $order = $this->cart();
        $coffeeLine = $this->addLine($order, $this->coffee, 2, $this->coffeeMonthly);
        $teaLine = $this->addLine($order, $this->tea, 1, $this->teaMonthly);
        $this->placeWithConsent($order);

        $subscriptions = $this->subscriptionsByPlan();
        self::assertSame(['COFFEE_MONTHLY+TEA_MONTHLY'], array_keys($subscriptions));

        $subscription = $subscriptions['COFFEE_MONTHLY+TEA_MONTHLY'];
        self::assertSame(SubscriptionInterface::STATE_PENDING, $subscription->getState());
        self::assertSame('john@example.com', $subscription->getCustomer()?->getEmail());
        self::assertSame($this->channel->getCode(), $subscription->getChannel()?->getCode());
        self::assertSame('USD', $subscription->getCurrencyCode());
        self::assertSame(1, $subscription->getBillingIntervalCount());
        self::assertSame(SubscriptionIntervalUnit::Month, $subscription->getBillingIntervalUnit());
        self::assertSame(1, $subscription->getDeliveryIntervalCount());
        self::assertSame(SubscriptionIntervalUnit::Month, $subscription->getDeliveryIntervalUnit());
        self::assertSame($this->paymentMethod->getCode(), $subscription->getPaymentMethod()?->getCode());
        self::assertSame($this->shippingMethod->getCode(), $subscription->getShippingMethod()?->getCode(), 'Coffee ships, so the batch renews with the order\'s shipping method.');
        self::assertSame('1', $subscription->getConsentVersion());
        self::assertStringStartsWith('I authorise the store to charge my payment method', (string) $subscription->getConsentText());
        self::assertNotNull($subscription->getConsentAcceptedAt());
        self::assertSame(0, $subscription->getConsecutiveFailedCycles());
        self::assertNull($subscription->getActivatedAt());
        self::assertCount(0, $subscription->getCycles());

        [$coffee, $tea] = $this->itemsOf($subscription);
        self::assertSame($this->coffee->getCode(), $coffee->getProductVariant()?->getCode());
        self::assertSame(2, $coffee->getQuantity());
        self::assertSame(9000, $coffee->getUnitPrice());
        self::assertSame('COFFEE_MONTHLY', $coffee->getPlan()?->getCode());
        self::assertSame($coffeeLine->getId(), $coffee->getOriginOrderItem()?->getId());
        self::assertSame(0, $coffee->getPaidCycles());

        self::assertSame($this->tea->getCode(), $tea->getProductVariant()?->getCode());
        self::assertSame(1, $tea->getQuantity());
        self::assertSame(5000, $tea->getUnitPrice());
        self::assertSame('TEA_MONTHLY', $tea->getPlan()?->getCode());
        self::assertSame($teaLine->getId(), $tea->getOriginOrderItem()?->getId());
    }

    public function testLinesOnTwoIntervalsStartASubscriptionPerInterval(): void
    {
        $order = $this->cart();
        $this->addLine($order, $this->coffee, 1, $this->coffeeMonthly);
        $this->addLine($order, $this->tea, 1, $this->teaMonthly);
        $this->addLine($order, $this->honey, 3, $this->honeyQuarterly);
        $this->placeWithConsent($order);

        $subscriptions = $this->subscriptionsByPlan();
        self::assertSame(['COFFEE_MONTHLY+TEA_MONTHLY', 'HONEY_QUARTERLY'], array_keys($subscriptions));

        $honey = $subscriptions['HONEY_QUARTERLY'];
        self::assertSame(3, $honey->getBillingIntervalCount());
        self::assertSame(SubscriptionIntervalUnit::Month, $honey->getBillingIntervalUnit());
        self::assertSame(3, $this->onlyItemOf($honey)->getQuantity());
        self::assertSame(2000, $this->onlyItemOf($honey)->getUnitPrice());
    }

    public function testOneOffLinesStartNothingAndABatchThatShipsNothingHasNoShippingMethod(): void
    {
        $order = $this->cart();
        $this->addLine($order, $this->coffee, 1, null);
        $this->addLine($order, $this->tea, 1, $this->teaEveryTwoWeeks);
        $this->placeWithConsent($order);

        $subscriptions = $this->subscriptionsByPlan();
        self::assertSame(['TEA_EVERY_TWO_WEEKS'], array_keys($subscriptions));

        $tea = $subscriptions['TEA_EVERY_TWO_WEEKS'];
        self::assertSame(2, $tea->getBillingIntervalCount());
        self::assertSame(SubscriptionIntervalUnit::Week, $tea->getBillingIntervalUnit());
        self::assertNull($tea->getShippingMethod(), 'A batch that ships nothing has no shipping method to renew with.');
    }

    public function testRepeatedLinesJoinThePlanLinesOfTheirIntervalAndKeepTheirFrequency(): void
    {
        $monthly = $this->storeFrequency('MONTHLY', 1, SubscriptionIntervalUnit::Month, 5, $this->tea, $this->honey);
        $order = $this->cart();
        $this->addLine($order, $this->coffee, 1, $this->coffeeMonthly);
        $teaLine = $this->addLine($order, $this->tea, 2, null);
        $this->addLine($order, $this->honey, 1, null);
        $this->repeat($order, $monthly);
        $this->placeWithConsent($order);

        $subscriptions = $this->subscriptionsByPlan();
        self::assertSame(['COFFEE_MONTHLY+MONTHLY+MONTHLY'], array_keys($subscriptions), 'A plan and a frequency of the same interval renew in one subscription.');

        $subscription = $subscriptions['COFFEE_MONTHLY+MONTHLY+MONTHLY'];
        self::assertSame(1, $subscription->getBillingIntervalCount());
        self::assertSame(SubscriptionIntervalUnit::Month, $subscription->getBillingIntervalUnit());

        [$coffee, $tea, $honey] = $this->itemsOf($subscription);
        self::assertSame('COFFEE_MONTHLY', $coffee->getPlan()?->getCode());
        self::assertNull($coffee->getFrequency());
        self::assertSame(9000, $coffee->getUnitPrice());

        self::assertNull($tea->getPlan());
        self::assertSame('MONTHLY', $tea->getFrequency()?->getCode());
        self::assertSame(2, $tea->getQuantity());
        self::assertSame(4750, $tea->getUnitPrice());
        self::assertSame($teaLine->getId(), $tea->getOriginOrderItem()?->getId());

        self::assertSame('MONTHLY', $honey->getFrequency()?->getCode());
        self::assertSame(1900, $honey->getUnitPrice());
    }

    public function testARepeatedLineOfAnotherIntervalStartsASubscriptionOfItsOwn(): void
    {
        $everyTwoWeeks = $this->storeFrequency('EVERY_TWO_WEEKS', 2, SubscriptionIntervalUnit::Week, 0, $this->tea);
        $order = $this->cart();
        $this->addLine($order, $this->coffee, 1, $this->coffeeMonthly);
        $this->addLine($order, $this->tea, 1, null);
        $this->repeat($order, $everyTwoWeeks);
        $this->placeWithConsent($order);

        $subscriptions = $this->subscriptionsByPlan();
        self::assertSame(['COFFEE_MONTHLY', 'EVERY_TWO_WEEKS'], array_keys($subscriptions));
        self::assertSame(2, $subscriptions['EVERY_TWO_WEEKS']->getBillingIntervalCount());
        self::assertSame(SubscriptionIntervalUnit::Week, $subscriptions['EVERY_TWO_WEEKS']->getBillingIntervalUnit());
    }

    public function testALineThatIsNotRepeatedStartsNothing(): void
    {
        $monthly = $this->storeFrequency('MONTHLY', 1, SubscriptionIntervalUnit::Month, 5, $this->tea);
        $order = $this->cart();
        $this->addLine($order, $this->coffee, 1, null);
        $this->addLine($order, $this->tea, 1, null);
        $this->repeat($order, $monthly);
        $this->placeWithConsent($order);

        $subscriptions = $this->subscriptionsByPlan();
        self::assertSame(['MONTHLY'], array_keys($subscriptions), 'Coffee cannot be repeated, so it was bought once.');
        self::assertSame($this->tea->getCode(), $this->onlyItemOf($subscriptions['MONTHLY'])->getProductVariant()?->getCode());
    }

    public function testPayingTheInitialOrderActivatesTheBatchOnceWithEveryItemPaidInItsFirstCycle(): void
    {
        $this->itIsNow('2026-09-22 10:00');
        $order = $this->cart();
        $this->addLine($order, $this->coffee, 2, $this->coffeeMonthly);
        $this->addLine($order, $this->tea, 1, $this->teaMonthly);
        $this->placeWithConsent($order);

        /** @var SubscriptionRepositoryInterface<SubscriptionInterface> $repository */
        $repository = self::getContainer()->get('jpm_martin_sylius_subscription.repository.subscription');
        self::assertCount(1, $repository->findByInitialOrder($order), 'A subscription of two lines of the order is still one subscription.');

        $this->pay($order);

        $subscription = $this->subscriptionsByPlan()['COFFEE_MONTHLY+TEA_MONTHLY'];
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState());

        [$first, $second] = $this->storedCycles($subscription);
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $first->getState());
        self::assertSame($order->getId(), $first->getOrder()?->getId());
        self::assertSame([[2, 9000, null], [1, 5000, null]], array_map(
            static fn ($cycleItem): array => [$cycleItem->getQuantity(), $cycleItem->getUnitPrice(), $cycleItem->getSkippedReason()],
            $first->getItems()->toArray(),
        ));
        foreach ($this->itemsOf($subscription) as $item) {
            self::assertSame(1, $item->getPaidCycles());
        }

        self::assertSame(SubscriptionCycleInterface::STATE_SCHEDULED, $second->getState());
        self::assertEquals(new \DateTimeImmutable('2026-10-22 10:00'), $second->getScheduledAt());
    }

    public function testARenewalOrderStartsNoSubscription(): void
    {
        $initial = $this->cart();
        $this->addLine($initial, $this->coffee, 1, $this->coffeeMonthly);
        $this->placeWithConsent($initial);
        $subscription = $this->subscriptionsByPlan()['COFFEE_MONTHLY'];

        // Even with a line that carries a plan, an order a cycle points at is a renewal.
        $renewal = $this->cart();
        $this->addLine($renewal, $this->coffee, 1, $this->coffeeMonthly);
        $this->entityManager()->persist($renewal);
        $cycleFactory = self::getContainer()->get('jpm_martin_sylius_subscription.factory.subscription_cycle');
        self::assertInstanceOf(FactoryInterface::class, $cycleFactory);
        $cycle = $cycleFactory->createNew();
        self::assertInstanceOf(SubscriptionCycleInterface::class, $cycle);
        $cycle->setNumber(2);
        $cycle->setScheduledAt(new \DateTimeImmutable('2026-10-22'));
        $cycle->setState(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT);
        $cycle->setOrder($renewal);
        $subscription->addCycle($cycle);
        $this->entityManager()->flush();

        $this->place($renewal);

        self::assertCount(1, $this->storedSubscriptions(), 'The renewal started a subscription of its own.');
    }

    /** @return list<SubscriptionItemInterface> in the order of the lines */
    private function itemsOf(SubscriptionInterface $subscription): array
    {
        return array_values($subscription->getItems()->toArray());
    }
}
