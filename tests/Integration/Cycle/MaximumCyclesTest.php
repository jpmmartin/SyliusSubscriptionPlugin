<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Cycle;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use Sylius\Component\Order\Model\OrderItemInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;

/**
 * A monthly batch of Coffee and Tea activated on 1 January, whose cycles the command renews on the
 * first of each month. Each item counts the cycles charged with it in them, the initial order
 * included, against its own plan's maximum.
 */
final class MaximumCyclesTest extends LifecycleTestCase
{
    public function testAnItemThatUsesUpItsPlanIsLeftOutOfTheCyclesAfterAndTheBatchRenewsWithTheRest(): void
    {
        $this->coffeeMonthly->setMaxCycles(2);
        $this->batchActivatedOnTheFirstOfJanuary();

        $this->renewOn('2027-02-01 09:00');
        $this->renewOn('2027-03-01 09:00');

        $subscription = $this->batch();
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState());
        [, $second, $third, $fourth] = $this->storedCycles($subscription);
        self::assertSame(['Coffee', 'Tea'], $this->productsOrderedIn($second));
        self::assertSame(['Tea'], $this->productsOrderedIn($third));
        self::assertSame(['Tea'], $this->productsRecordedIn($third), 'An item that no longer renews is not recorded as skipped.');
        self::assertSame([2, 3], $this->paidCyclesOfTheItems($subscription));
        self::assertSame(SubscriptionCycleInterface::STATE_SCHEDULED, $fourth->getState());
        self::assertSame('2027-04-01 09:00', $fourth->getScheduledAt()?->format('Y-m-d H:i'));
    }

    public function testASubscriptionWhoseItemsAllUseUpTheirPlansCompletesWithoutSchedulingAnother(): void
    {
        $this->coffeeMonthly->setMaxCycles(2);
        $this->teaMonthly->setMaxCycles(2);
        $this->batchActivatedOnTheFirstOfJanuary();

        $this->renewOn('2027-02-01 09:00');

        $subscription = $this->batch();
        self::assertSame(SubscriptionInterface::STATE_COMPLETED, $subscription->getState());
        self::assertSame(
            [SubscriptionCycleInterface::STATE_PAID, SubscriptionCycleInterface::STATE_PAID],
            array_map(static fn (SubscriptionCycleInterface $cycle): string => $cycle->getState(), $this->storedCycles($subscription)),
        );
        self::assertSame([2, 2], $this->paidCyclesOfTheItems($subscription));
    }

    public function testASkippedItemDoesNotUseUpItsPlan(): void
    {
        $this->coffeeMonthly->setMaxCycles(2);
        $this->batchActivatedOnTheFirstOfJanuary();

        $this->coffee->setEnabled(false);
        $this->entityManager()->flush();
        $this->renewOn('2027-02-01 09:00');
        self::assertSame([1, 2], $this->paidCyclesOfTheItems($this->batch()));

        // The command started afresh, so Coffee is loaded again to be changed.
        $coffee = $this->entityManager()->find($this->coffee::class, $this->coffee->getId());
        self::assertNotNull($coffee);
        $coffee->setEnabled(true);
        $this->entityManager()->flush();
        $this->renewOn('2027-03-01 09:00');
        $this->renewOn('2027-04-01 09:00');

        $cycles = $this->storedCycles($this->batch());
        self::assertSame(['Tea'], $this->productsOrderedIn($cycles[1]));
        self::assertSame(['Coffee', 'Tea'], $this->productsOrderedIn($cycles[2]), 'Coffee still had a cycle left.');
        self::assertSame(['Tea'], $this->productsOrderedIn($cycles[3]));
        self::assertSame([2, 4], $this->paidCyclesOfTheItems($this->batch()));
    }

    public function testAnItemRepeatedWithAFrequencyRenewsUpToTheFrequencysMaximum(): void
    {
        $monthly = $this->storeFrequency('MONTHLY', 1, SubscriptionIntervalUnit::Month, 0, $this->tea);
        $monthly->setMaxCycles(3);
        $this->itIsNow('2027-01-01 09:00');
        $order = $this->cart();
        $this->addLine($order, $this->tea, 1, null);
        $this->repeat($order, $monthly);
        $this->placeWithConsent($order);
        $this->pay($order);

        $this->renewOn('2027-02-01 09:00');
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $this->subscriptionsByPlan()['MONTHLY']->getState());
        $this->renewOn('2027-03-01 09:00');

        $subscription = $this->subscriptionsByPlan()['MONTHLY'];
        self::assertSame(SubscriptionInterface::STATE_COMPLETED, $subscription->getState());
        self::assertCount(3, $this->storedCycles($subscription), 'No fourth cycle is scheduled.');
        self::assertSame(3, $this->onlyItemOf($subscription)->getPaidCycles());
    }

    private function batchActivatedOnTheFirstOfJanuary(): void
    {
        $this->entityManager()->flush();
        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedBatchOrder());
    }

    private function renewOn(string $dateTime): void
    {
        $this->itIsNow($dateTime);
        $this->runTheCycleCommand();
    }

    private function batch(): SubscriptionInterface
    {
        return $this->subscriptionsByPlan()['COFFEE_MONTHLY+TEA_MONTHLY'];
    }

    /** @return list<string|null> */
    private function productsOrderedIn(SubscriptionCycleInterface $cycle): array
    {
        $order = $cycle->getOrder();
        self::assertNotNull($order);

        return array_values(array_map(static fn (OrderItemInterface $line): ?string => $line->getProductName(), $order->getItems()->toArray()));
    }

    /** @return list<string|null> */
    private function productsRecordedIn(SubscriptionCycleInterface $cycle): array
    {
        return array_values(array_map(
            static fn (SubscriptionCycleItemInterface $recorded): ?string => $recorded->getSubscriptionItem()?->getProductVariant()?->getProduct()?->getName(),
            $cycle->getItems()->toArray(),
        ));
    }

    /** @return list<int> Coffee's, then Tea's */
    private function paidCyclesOfTheItems(SubscriptionInterface $subscription): array
    {
        $paidCycles = [];
        foreach ($subscription->getItems() as $item) {
            $this->entityManager()->refresh($item);
            $paidCycles[] = $item->getPaidCycles();
        }

        return $paidCycles;
    }
}
