<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Cycle;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionFrequencyChangerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionInterval;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;

/**
 * Coffee at $100.00 on its monthly plan with 10% off, and an introductory discount of 50%: a
 * subscription activated on 1 January, renewed by the command on the first of each month.
 */
final class IntroductoryPriceTest extends LifecycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->coffeeMonthly->setIntroductoryDiscountPercentage(50);
    }

    public function testWithOneIntroductoryCycleOnlyTheInitialOrderIsAtTheIntroductoryPrice(): void
    {
        $this->subscriptionActivatedOnTheFirstOfJanuary();

        $this->renewOn('2027-02-01 09:00');
        $this->renewOn('2027-03-01 09:00');

        $cycles = $this->storedCycles($this->subscription());
        self::assertSame([5000, 9000, 9000], $this->itemsTotalsOf(\array_slice($cycles, 0, 3)));
        self::assertSame([5000, 9000, 9000], $this->recordedUnitPricesOf(\array_slice($cycles, 0, 3)));
    }

    public function testWithThreeIntroductoryCyclesTheTwoFirstRenewalsAreAtTheIntroductoryPriceToo(): void
    {
        $this->coffeeMonthly->setIntroductoryCycles(3);
        $this->subscriptionActivatedOnTheFirstOfJanuary();

        $this->renewOn('2027-02-01 09:00');
        $this->renewOn('2027-03-01 09:00');
        $this->renewOn('2027-04-01 09:00');

        $cycles = $this->storedCycles($this->subscription());
        self::assertSame([5000, 5000, 5000, 9000], $this->itemsTotalsOf(\array_slice($cycles, 0, 4)));
        self::assertSame([5000, 5000, 5000, 9000], $this->recordedUnitPricesOf(\array_slice($cycles, 0, 4)));
        self::assertSame(9000, $this->subscription()->getRenewalTotal(), 'The price the subscription renews at once the introductory one ends.');
    }

    public function testChangingTheFrequencyDuringTheIntroductoryCyclesEndsThem(): void
    {
        $this->coffeeMonthly->setIntroductoryCycles(3);
        $coffeeQuarterly = $this->plan($this->coffee, 'COFFEE_QUARTERLY', 3, SubscriptionIntervalUnit::Month, 15);
        $coffeeQuarterly->setIntroductoryDiscountPercentage(60);
        $this->subscriptionActivatedOnTheFirstOfJanuary();

        $this->itIsNow('2027-01-15 09:00');
        /** @var SubscriptionFrequencyChangerInterface $changer */
        $changer = self::getContainer()->get('jpm_martin_sylius_subscription.management.frequency_changer');
        $changer->change($this->subscription(), new SubscriptionInterval(3, SubscriptionIntervalUnit::Month));
        $this->entityManager()->flush();

        $item = $this->onlyItemOf($this->subscription());
        self::assertNull($item->getIntroductoryUnitPrice(), 'The new plan\'s own introductory price is for new subscribers.');
        self::assertNull($item->getIntroductoryCycles());

        $this->renewOn('2027-02-01 09:00');

        $cycles = $this->storedCycles($this->subscription());
        self::assertSame(8500, $cycles[1]->getOrder()?->getItemsTotal(), 'The normal price of the quarterly plan: $100.00 less 15%.');
    }

    private function subscriptionActivatedOnTheFirstOfJanuary(): void
    {
        $this->entityManager()->flush();
        $this->itIsNow('2027-01-01 09:00');
        $order = $this->placedCoffeeOrder();
        self::assertSame(5000, $order->getItemsTotal(), 'The initial order is the first cycle, at $100.00 less 50%.');
        $this->pay($order);
    }

    private function renewOn(string $dateTime): void
    {
        $this->itIsNow($dateTime);
        $this->runTheCycleCommand();
    }

    private function subscription(): SubscriptionInterface
    {
        $subscriptions = $this->subscriptionsByPlan();
        self::assertCount(1, $subscriptions);

        return $subscriptions[array_key_first($subscriptions)];
    }

    /**
     * @param list<SubscriptionCycleInterface> $cycles
     *
     * @return list<int|null>
     */
    private function itemsTotalsOf(array $cycles): array
    {
        return array_map(static fn (SubscriptionCycleInterface $cycle): ?int => $cycle->getOrder()?->getItemsTotal(), $cycles);
    }

    /**
     * @param list<SubscriptionCycleInterface> $cycles
     *
     * @return list<int>
     */
    private function recordedUnitPricesOf(array $cycles): array
    {
        return array_map(static function (SubscriptionCycleInterface $cycle): int {
            $recorded = $cycle->getItems()->first();
            self::assertInstanceOf(SubscriptionCycleItemInterface::class, $recorded);

            return $recorded->getUnitPrice();
        }, $cycles);
    }
}
