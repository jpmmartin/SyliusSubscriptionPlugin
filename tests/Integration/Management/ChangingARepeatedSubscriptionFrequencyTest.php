<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Management;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Factory\SubscriptionPlanFactoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionFrequencyChangerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionInterval;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;

/**
 * A monthly subscription of Coffee on its monthly plan and Honey repeated with the store's monthly
 * frequency, activated on 1 January. Coffee also has a quarterly plan and one every two weeks; the
 * store has two quarterly frequencies and none every two weeks, and Honey has a quarterly plan of its
 * own, which a repeated item never moves to.
 */
final class ChangingARepeatedSubscriptionFrequencyTest extends LifecycleTestCase
{
    private SubscriptionFrequencyInterface $quarterly;

    private SubscriptionFrequencyInterface $quarterlyLater;

    private int $subscriptionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anotherPlan($this->coffee, 'COFFEE_QUARTERLY', 3, SubscriptionIntervalUnit::Month, 15);
        $this->anotherPlan($this->coffee, 'COFFEE_EVERY_TWO_WEEKS', 2, SubscriptionIntervalUnit::Week, 0);
        $monthly = $this->storeFrequency('MONTHLY', 1, SubscriptionIntervalUnit::Month, 5, $this->honey);
        $this->quarterly = $this->storeFrequency('QUARTERLY', 3, SubscriptionIntervalUnit::Month, 10);
        $this->quarterlyLater = $this->storeFrequency('QUARTERLY_LATER', 3, SubscriptionIntervalUnit::Month, 50);

        $this->itIsNow('2027-01-01 09:00');
        $order = $this->cart();
        $this->addLine($order, $this->coffee, 1, $this->coffeeMonthly);
        $this->addLine($order, $this->honey, 1, null);
        $this->repeat($order, $monthly);
        $this->placeWithConsent($order);
        $this->pay($order);
        $this->subscriptionId = (int) $this->subscriptionsByPlan()['COFFEE_MONTHLY+MONTHLY']->getId();
    }

    public function testOnlyTheIntervalsTheItemsPlansAndTheStoresFrequenciesShareAreOffered(): void
    {
        self::assertSame(['3-month'], $this->offeredKeys(), 'Coffee could renew every two weeks, but the store has no such frequency for Honey.');
    }

    public function testEachItemMovesToItsOwnKindOfTermsWithTheNewPrice(): void
    {
        $this->itIsNow('2027-01-15 09:00');

        $this->changer()->change($this->subscription(), new SubscriptionInterval(3, SubscriptionIntervalUnit::Month));
        $this->entityManager()->flush();

        $subscription = $this->subscription();
        self::assertSame(3, $subscription->getBillingIntervalCount());
        self::assertSame(SubscriptionIntervalUnit::Month, $subscription->getBillingIntervalUnit());
        self::assertSame(
            [['COFFEE_QUARTERLY', null, 8500], [null, 'QUARTERLY', 1800]],
            array_map(
                static fn (SubscriptionItemInterface $item): array => [$item->getPlan()?->getCode(), $item->getFrequency()?->getCode(), $item->getUnitPrice()],
                array_values($subscription->getItems()->toArray()),
            ),
            'Coffee at 100.00 less 15% on its plan; Honey at 20.00 less 10% on the oldest quarterly frequency.',
        );
    }

    public function testARepeatedItemDoesNotMoveToAPlanOfItsVariant(): void
    {
        $this->quarterly->disable();
        $this->quarterlyLater->disable();
        $this->entityManager()->flush();

        self::assertSame([], $this->offeredKeys(), 'Honey has a quarterly plan, but was repeated with a store frequency.');
    }

    public function testAFrequencyOfAnotherChannelIsNotOffered(): void
    {
        foreach ([$this->quarterly, $this->quarterlyLater] as $frequency) {
            $frequency->removeChannel($this->channel);
        }
        $this->entityManager()->flush();

        self::assertSame([], $this->offeredKeys());
    }

    private function subscription(): SubscriptionInterface
    {
        foreach ($this->storedSubscriptions() as $subscription) {
            if ($subscription->getId() === $this->subscriptionId) {
                return $subscription;
            }
        }

        self::fail('The subscription is gone.');
    }

    /** @return list<string> */
    private function offeredKeys(): array
    {
        return array_map(static fn (SubscriptionInterval $interval): string => $interval->key(), $this->changer()->frequenciesToChangeTo($this->subscription()));
    }

    private function changer(): SubscriptionFrequencyChangerInterface
    {
        $changer = self::getContainer()->get(SubscriptionFrequencyChangerInterface::class);
        self::assertInstanceOf(SubscriptionFrequencyChangerInterface::class, $changer);

        return $changer;
    }

    private function anotherPlan(ProductVariantInterface $variant, string $code, int $intervalCount, SubscriptionIntervalUnit $unit, int $discount): void
    {
        /** @var SubscriptionPlanFactoryInterface $factory */
        $factory = self::getContainer()->get('jpm_martin_sylius_subscription.factory.subscription_plan');
        $plan = $factory->createForVariant($variant);
        $plan->setCode($code);
        $plan->setName($code);
        $plan->setIntervalCount($intervalCount);
        $plan->setIntervalUnit($unit);
        $plan->setDiscountPercentage($discount);
        $this->entityManager()->persist($plan);
        $this->entityManager()->flush();
    }
}
