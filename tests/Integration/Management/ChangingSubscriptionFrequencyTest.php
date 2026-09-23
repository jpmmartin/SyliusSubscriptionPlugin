<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Management;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use JpmMartin\SyliusSubscriptionPlugin\Factory\SubscriptionPlanFactoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionFrequencyChangerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Order\RenewalOrderPlacerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionInterval;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;

/**
 * A monthly batch of Coffee and Tea activated on 1 January. Both variants also have a quarterly plan,
 * Tea two of them; Tea alone renews every two weeks, and Coffee's weekly plan is disabled.
 */
final class ChangingSubscriptionFrequencyTest extends LifecycleTestCase
{
    private SubscriptionPlanInterface $coffeeQuarterly;

    private SubscriptionPlanInterface $teaQuarterly;

    private int $batchId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coffeeQuarterly = $this->anotherPlan($this->coffee, 'COFFEE_QUARTERLY', 3, SubscriptionIntervalUnit::Month, 15);
        $this->anotherPlan($this->coffee, 'COFFEE_WEEKLY', 1, SubscriptionIntervalUnit::Week, 5)->disable();
        $this->teaQuarterly = $this->anotherPlan($this->tea, 'TEA_QUARTERLY', 3, SubscriptionIntervalUnit::Month, 5);
        $this->entityManager()->flush();
        $this->anotherPlan($this->tea, 'TEA_QUARTERLY_LATER', 3, SubscriptionIntervalUnit::Month, 50);
        $this->entityManager()->flush();

        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedBatchOrder());
        $this->batchId = (int) $this->subscriptionsByPlan()['COFFEE_MONTHLY+TEA_MONTHLY']->getId();
    }

    public function testOnlyTheIntervalsEveryVariantOfTheBatchHasAnEnabledPlanForAreOffered(): void
    {
        self::assertSame(['3-month'], $this->offeredKeys());
    }

    public function testFromMonthlyToQuarterlyEachItemMovesToItsVariantsOldestPlanWithTheNewPriceFromTheOpenCycle(): void
    {
        $coffeePricing = $this->coffee->getChannelPricingForChannel($this->channel);
        self::assertInstanceOf(ChannelPricingInterface::class, $coffeePricing);
        $coffeePricing->setPrice(11000);
        $this->itIsNow('2027-01-15 09:00');

        $this->changer()->change($this->batch(), new SubscriptionInterval(3, SubscriptionIntervalUnit::Month));
        $this->entityManager()->flush();

        $subscription = $this->batch();
        self::assertSame(3, $subscription->getBillingIntervalCount());
        self::assertSame(SubscriptionIntervalUnit::Month, $subscription->getBillingIntervalUnit());
        self::assertSame(3, $subscription->getDeliveryIntervalCount());
        self::assertSame(
            [['COFFEE_QUARTERLY', 9350], ['TEA_QUARTERLY', 4750]],
            array_map(static fn (SubscriptionItemInterface $item): array => [$item->getPlan()?->getCode(), $item->getUnitPrice()], array_values($subscription->getItems()->toArray())),
            'Coffee at its current 110.00 less 15%, Tea at 50.00 less 5%, from its oldest quarterly plan.',
        );

        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();

        $cycles = $this->storedCycles($this->batch());
        self::assertSame('2027-02-01 09:00', $cycles[1]->getScheduledAt()?->format('Y-m-d H:i'), 'The open cycle keeps its date.');
        self::assertSame(14100, $cycles[1]->getOrder()?->getItemsTotal(), 'The open cycle is charged at the quarterly prices.');
        self::assertSame('2027-05-01 09:00', $cycles[2]->getScheduledAt()?->format('Y-m-d H:i'), 'Three months after the open cycle.');
    }

    public function testAnIntervalSomeVariantOfTheBatchHasNoPlanForIsRefused(): void
    {
        $subscription = $this->batch();

        try {
            $this->changer()->change($subscription, new SubscriptionInterval(2, SubscriptionIntervalUnit::Week));
            self::fail('The batch moved to an interval only Tea has a plan for.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame(1, $subscription->getBillingIntervalCount());
        self::assertSame(['COFFEE_MONTHLY', 'TEA_MONTHLY'], $this->planCodesOf($subscription));
    }

    public function testAnItemThatNoLongerRenewsMovesToTheNewPlanTooAndRenewsAgainUnderItsMaximum(): void
    {
        $this->coffeeMonthly->setMaxCycles(1);
        $this->entityManager()->flush();
        $batch = $this->batch();
        $coffee = $batch->getItems()->first();
        self::assertInstanceOf(SubscriptionItemInterface::class, $coffee);
        self::assertFalse($coffee->isRenewable(), 'Coffee has had its only cycle.');
        self::assertSame(['3-month'], $this->offeredKeys(), 'Coffee still has its say in the intervals offered.');

        $this->changer()->change($batch, new SubscriptionInterval(3, SubscriptionIntervalUnit::Month));

        self::assertSame(['COFFEE_QUARTERLY', 'TEA_QUARTERLY'], $this->planCodesOf($batch));
        self::assertTrue($coffee->isRenewable(), 'The quarterly plan has no maximum, so Coffee renews again.');
    }

    public function testAnItemThatNoLongerRenewsStillNeedsAPlanForTheInterval(): void
    {
        $this->coffeeMonthly->setMaxCycles(1);
        $this->coffeeQuarterly->disable();
        $this->entityManager()->flush();

        self::assertSame([], $this->offeredKeys(), 'Tea could go quarterly, but Coffee has no quarterly plan.');
    }

    public function testNoFrequencyIsOfferedWhileTheOpenCycleAwaitsPayment(): void
    {
        $this->itIsNow('2027-02-01 09:00');
        $placer = self::getContainer()->get(RenewalOrderPlacerInterface::class);
        self::assertInstanceOf(RenewalOrderPlacerInterface::class, $placer);
        $cycle = $this->storedCycles($this->batch())[1];
        $placer->place($cycle);
        $this->apply($cycle, 'jpm_martin_sylius_subscription_cycle', 'place_order');
        $this->entityManager()->flush();

        self::assertSame([], $this->offeredKeys());
    }

    public function testNoFrequencyIsOfferedToASubscriptionThatIsNotActive(): void
    {
        $subscription = $this->batch();
        $this->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_SUSPEND);
        $this->entityManager()->flush();

        self::assertSame([], $this->offeredKeys());
    }

    private function batch(): SubscriptionInterface
    {
        foreach ($this->storedSubscriptions() as $subscription) {
            if ($subscription->getId() === $this->batchId) {
                return $subscription;
            }
        }

        self::fail('The batch is gone.');
    }

    /** @return list<string> */
    private function offeredKeys(): array
    {
        return array_map(static fn (SubscriptionInterval $interval): string => $interval->key(), $this->changer()->frequenciesToChangeTo($this->batch()));
    }

    /** @return list<string|null> */
    private function planCodesOf(SubscriptionInterface $subscription): array
    {
        return array_values(array_map(static fn (SubscriptionItemInterface $item): ?string => $item->getPlan()?->getCode(), $subscription->getItems()->toArray()));
    }

    private function changer(): SubscriptionFrequencyChangerInterface
    {
        $changer = self::getContainer()->get(SubscriptionFrequencyChangerInterface::class);
        self::assertInstanceOf(SubscriptionFrequencyChangerInterface::class, $changer);

        return $changer;
    }

    private function anotherPlan(ProductVariantInterface $variant, string $code, int $intervalCount, SubscriptionIntervalUnit $unit, int $discount): SubscriptionPlanInterface
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

        return $plan;
    }
}
