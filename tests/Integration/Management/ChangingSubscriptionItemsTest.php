<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Management;

use JpmMartin\SyliusSubscriptionPlugin\Consent\SubscriptionConsentRecorder;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionItemsChanged;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionFrequencyChangerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionItemChanges;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionItemEditorInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionItemOffer;
use JpmMartin\SyliusSubscriptionPlugin\Query\CommittedCyclesQueryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionInterval;
use Sylius\Behat\Context\Setup\ProductContext;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Event\EventCollector;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

/**
 * Subscriptions activated on 1 January, renewing monthly: Coffee at $100.00 on its monthly plan with
 * 10% off, so $90.00 frozen, and Tea at $50.00 on its monthly plan with no discount. Honey has only a
 * quarterly plan.
 */
final class ChangingSubscriptionItemsTest extends LifecycleTestCase
{
    public function testAHigherQuantityGoesIntoTheNextRenewalAtTheFrozenPrice(): void
    {
        $subscription = $this->coffeeSubscription();
        $this->coffee->getChannelPricingForChannel($this->channel)?->setPrice(12000);
        $this->entityManager()->flush();

        $this->itIsNow('2027-01-20 09:00');
        $changes = new SubscriptionItemChanges();
        $changes->edit($this->itemOf($subscription, $this->coffee))->quantity = 2;
        self::assertSame(18000, $this->editor()->renewalTotalAfter($subscription, $changes));
        self::assertTrue($this->editor()->apply($subscription, $changes, 'en_US'));
        $this->entityManager()->flush();

        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();

        $line = $this->lineOf($this->renewalOrder($subscription, 2), $this->coffee);
        self::assertSame(2, $line->getQuantity());
        self::assertSame(9000, $line->getUnitPrice(), 'The price frozen on 1 January, not the $120.00 of today less 10%.');
    }

    public function testAChangedQuantityPublishesTheRenewalTotalsBeforeAndAfter(): void
    {
        $subscription = $this->coffeeSubscription();

        $changes = new SubscriptionItemChanges();
        $changes->edit($this->itemOf($subscription, $this->coffee))->quantity = 2;
        $this->editor()->apply($subscription, $changes, 'en_US');
        $this->entityManager()->flush();

        self::assertEquals(
            [new SubscriptionItemsChanged((int) $subscription->getId(), 9000, 18000)],
            $this->collector()->events(SubscriptionItemsChanged::class),
        );
    }

    public function testAnItemMovesToAnotherSizeAtItsCurrentPriceLessItsPlansDiscountKeepingItsPaidCycles(): void
    {
        [$medium, $large] = $this->tShirtSizes();
        $subscription = $this->subscriptionOf($medium, 'TSHIRT_M_MONTHLY');
        $shirt = $this->itemOf($subscription, $medium);
        self::assertSame(1, $shirt->getPaidCycles());

        self::assertSame(['T_SHIRT_L'], $this->variants($this->editor()->variantsFor($shirt)), 'XL has no monthly plan.');

        $changes = new SubscriptionItemChanges();
        $changes->edit($shirt)->variant = $large;
        $this->editor()->apply($subscription, $changes, 'en_US');
        $this->entityManager()->flush();

        $shirt = $this->itemOf($this->refreshed($subscription), $large);
        self::assertSame('TSHIRT_L_MONTHLY', $shirt->getPlan()?->getCode());
        self::assertSame(2250, $shirt->getUnitPrice(), '$25.00 less 10%.');
        self::assertSame(1, $shirt->getPaidCycles());
        self::assertNotContains('T_SHIRT_XL', $this->variants($this->editor()->variantsFor($shirt)));
    }

    public function testASizeWhosePlanAllowsNoMoreCyclesThanTheItemWasPaidIsNotOffered(): void
    {
        [$medium, $large] = $this->tShirtSizes();
        $largeMonthly = $this->planOf($large, 'TSHIRT_L_MONTHLY');
        $largeMonthly->setMaxCycles(1);
        $this->entityManager()->flush();
        $subscription = $this->subscriptionOf($medium, 'TSHIRT_M_MONTHLY');

        self::assertSame([], $this->variants($this->editor()->variantsFor($this->itemOf($subscription, $medium))));
    }

    public function testTheOnlyItemStillRenewingCannotBeRemoved(): void
    {
        $subscription = $this->coffeeSubscription();
        $coffee = $this->itemOf($subscription, $this->coffee);

        self::assertFalse($this->editor()->canRemove($coffee));

        $changes = new SubscriptionItemChanges();
        $changes->edit($coffee)->removed = true;
        $this->expectException(\InvalidArgumentException::class);
        $this->editor()->apply($subscription, $changes);
    }

    public function testNothingCanBeChangedWhileTheOpenCyclesOrderAwaitsPayment(): void
    {
        $subscription = $this->coffeeSubscription();
        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();
        $subscription = $this->refreshed($subscription);
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $this->cycle($subscription, 2)->getState());

        self::assertFalse($this->editor()->canEdit($subscription));
        self::assertCount(0, $this->editor()->editableItems($subscription));
        self::assertSame([], $this->variants($this->editor()->variantsToAdd($subscription)));

        $changes = new SubscriptionItemChanges();
        $changes->edit($this->itemOf($subscription, $this->coffee))->quantity = 3;

        try {
            $this->editor()->apply($subscription, $changes, 'en_US');
            self::fail('The items were changed while the order awaited payment.');
        } catch (\InvalidArgumentException) {
        }
        $this->entityManager()->flush();

        self::assertSame(1, $this->itemOf($this->refreshed($subscription), $this->coffee)->getQuantity());
        self::assertCount(0, $this->collector()->events(SubscriptionItemsChanged::class));
    }

    public function testTeaWithAMonthlyPlanIsAddedAndGoesIntoTheNextRenewalAtItsPlansPrice(): void
    {
        $subscription = $this->coffeeSubscription();

        $offers = $this->editor()->variantsToAdd($subscription);
        self::assertSame(['TEA'], $this->variants($offers), 'Honey has only a quarterly plan, and Coffee is already in.');
        self::assertSame('TEA_MONTHLY', $offers[0]->terms->getCode());
        self::assertSame(5000, $offers[0]->unitPrice);

        $this->itIsNow('2027-01-20 09:00');
        $changes = new SubscriptionItemChanges();
        $changes->add($this->tea, 1);
        $this->editor()->apply($subscription, $changes, 'en_US');
        $this->entityManager()->flush();

        $tea = $this->itemOf($this->refreshed($subscription), $this->tea);
        self::assertSame('TEA_MONTHLY', $tea->getPlan()?->getCode());
        self::assertSame(0, $tea->getPaidCycles());

        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();

        $order = $this->renewalOrder($subscription, 2);
        self::assertSame(9000, $this->lineOf($order, $this->coffee)->getUnitPrice());
        self::assertSame(5000, $this->lineOf($order, $this->tea)->getUnitPrice());
        self::assertSame(1, $this->itemOf($this->refreshed($subscription), $this->tea)->getPaidCycles());
    }

    public function testAVariantWithAPlanIsAddedOnItsPlanAndARepeatableOneOnTheStoresFrequency(): void
    {
        $this->storeFrequency('MONTHLY', 1, SubscriptionIntervalUnit::Month, 5, $this->tea, $this->honey);
        $subscription = $this->coffeeSubscription();

        $termsByVariant = [];
        foreach ($this->editor()->variantsToAdd($subscription) as $offer) {
            $termsByVariant[(string) $offer->variant->getCode()] = $offer->terms->getCode();
        }

        self::assertSame(['HONEY' => 'MONTHLY', 'TEA' => 'TEA_MONTHLY'], $termsByVariant, 'Honey on the store\'s frequency, Tea on its plan.');
    }

    public function testAVariantAlreadyInTheSubscriptionCannotBeAddedAgain(): void
    {
        $subscription = $this->coffeeSubscription();
        self::assertNotContains('COFFEE', $this->variants($this->editor()->variantsToAdd($subscription)));

        $changes = new SubscriptionItemChanges();
        $changes->add($this->coffee, 1);
        $this->expectException(\InvalidArgumentException::class);
        $this->editor()->apply($subscription, $changes, 'en_US');
    }

    public function testRaisingTheTotalIsSavedOnlyWithTheConsentAcceptedAndRecordsThatAcceptance(): void
    {
        $subscription = $this->coffeeSubscription();
        $subscription->setConsentVersion('0');
        $this->entityManager()->flush();

        $this->itIsNow('2027-01-20 09:00');
        $changes = new SubscriptionItemChanges();
        $changes->edit($this->itemOf($subscription, $this->coffee))->quantity = 2;
        self::assertTrue($this->editor()->requiresConsent($subscription, $changes));

        try {
            $this->editor()->apply($subscription, $changes);
            self::fail('The total was raised without the consent.');
        } catch (\InvalidArgumentException) {
        }
        $this->entityManager()->flush();
        $subscription = $this->refreshed($subscription);
        self::assertSame(1, $this->itemOf($subscription, $this->coffee)->getQuantity());
        self::assertSame('0', $subscription->getConsentVersion());

        $changes = new SubscriptionItemChanges();
        $changes->edit($this->itemOf($subscription, $this->coffee))->quantity = 2;
        $this->editor()->apply($subscription, $changes, 'en_US');
        $this->entityManager()->flush();

        $subscription = $this->refreshed($subscription);
        self::assertSame(2, $this->itemOf($subscription, $this->coffee)->getQuantity());
        self::assertSame('1', $subscription->getConsentVersion());
        self::assertSame($this->translator()->trans(SubscriptionConsentRecorder::TEXT_KEY, [], 'messages', 'en_US'), $subscription->getConsentText());
        self::assertNotSame(SubscriptionConsentRecorder::TEXT_KEY, $subscription->getConsentText());
        self::assertEquals(new \DateTimeImmutable('2027-01-20 09:00'), $subscription->getConsentAcceptedAt());
    }

    public function testAddingAFreeProductAsksForNoConsent(): void
    {
        $this->plan($this->honey, 'HONEY_MONTHLY_FREE', 1, SubscriptionIntervalUnit::Month, 100);
        $this->entityManager()->flush();
        $subscription = $this->coffeeSubscription();

        $changes = new SubscriptionItemChanges();
        $changes->add($this->honey, 1);
        self::assertFalse($this->editor()->requiresConsent($subscription, $changes));
        self::assertTrue($this->editor()->apply($subscription, $changes));
        $this->entityManager()->flush();

        self::assertSame(0, $this->itemOf($this->refreshed($subscription), $this->honey)->getUnitPrice());
    }

    public function testRemovingAnItemAsksForNoConsentAndKeepsItInTheCyclesThatCarriedIt(): void
    {
        $this->pay($this->placedBatchOrder());
        $subscription = $this->subscriptionsByPlan()['COFFEE_MONTHLY+TEA_MONTHLY'];
        $consentAcceptedAt = $subscription->getConsentAcceptedAt();
        $tea = $this->itemOf($subscription, $this->tea);
        self::assertTrue($this->editor()->canRemove($tea));

        $this->itIsNow('2027-01-20 09:00');
        $changes = new SubscriptionItemChanges();
        $changes->edit($tea)->removed = true;
        self::assertFalse($this->editor()->requiresConsent($subscription, $changes));
        $this->editor()->apply($subscription, $changes);
        $this->entityManager()->flush();

        $subscription = $this->refreshed($subscription);
        $tea = $this->itemOf($subscription, $this->tea);
        self::assertTrue($tea->isRemoved());
        self::assertFalse($tea->isRenewable());
        self::assertSame(9000, $subscription->getRenewalTotal());
        self::assertEquals($consentAcceptedAt, $subscription->getConsentAcceptedAt());
        self::assertCount(2, $this->cycle($subscription, 1)->getItems(), 'The first cycle still shows the tea it carried.');
        self::assertSame(
            [$this->itemOf($subscription, $this->coffee)->getId()],
            array_map(static fn (SubscriptionItemInterface $item): ?int => $item->getId(), $this->editor()->editableItems($subscription)),
        );

        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();
        self::assertCount(1, $this->renewalOrder($subscription, 2)->getItems());
    }

    public function testARemovedItemNeitherLimitsTheFrequenciesOfferedNorCountsAsCommitted(): void
    {
        $this->plan($this->coffee, 'COFFEE_QUARTERLY', 3, SubscriptionIntervalUnit::Month, 0);
        $this->entityManager()->flush();
        $this->pay($this->placedBatchOrder());
        $subscription = $this->subscriptionsByPlan()['COFFEE_MONTHLY+TEA_MONTHLY'];
        self::assertNotContains('3-month', $this->frequencyKeys($subscription), 'Tea has no quarterly plan.');

        $changes = new SubscriptionItemChanges();
        $changes->edit($this->itemOf($subscription, $this->tea))->removed = true;
        $this->editor()->apply($subscription, $changes);
        $this->entityManager()->flush();

        self::assertContains('3-month', $this->frequencyKeys($this->refreshed($subscription)));
        /** @var CommittedCyclesQueryInterface $committedCycles */
        $committedCycles = self::getContainer()->get(CommittedCyclesQueryInterface::class);
        self::assertCount(0, $committedCycles->forProductVariant($this->tea, new \DateInterval('P3M')));
        /** @var SubscriptionRepositoryInterface<SubscriptionInterface> $subscriptions */
        $subscriptions = self::getContainer()->get('jpm_martin_sylius_subscription.repository.subscription');
        self::assertCount(0, $subscriptions->findActiveByProductVariant($this->tea));
        self::assertNotCount(0, $committedCycles->forProductVariant($this->coffee, new \DateInterval('P3M')));
    }

    public function testARemovedItemsVariantCanBeAddedAgainAsANewItem(): void
    {
        $this->pay($this->placedBatchOrder());
        $subscription = $this->subscriptionsByPlan()['COFFEE_MONTHLY+TEA_MONTHLY'];
        $changes = new SubscriptionItemChanges();
        $changes->edit($this->itemOf($subscription, $this->tea))->removed = true;
        $this->editor()->apply($subscription, $changes);
        $this->entityManager()->flush();

        $subscription = $this->refreshed($subscription);
        self::assertSame(['TEA'], $this->variants($this->editor()->variantsToAdd($subscription)));
        $changes = new SubscriptionItemChanges();
        $changes->add($this->tea, 2);
        $this->editor()->apply($subscription, $changes, 'en_US');
        $this->entityManager()->flush();

        $subscription = $this->refreshed($subscription);
        self::assertCount(3, $subscription->getItems());
        self::assertSame(9000 + 2 * 5000, $subscription->getRenewalTotal());
        /** @var CommittedCyclesQueryInterface $committedCycles */
        $committedCycles = self::getContainer()->get(CommittedCyclesQueryInterface::class);
        $committed = $committedCycles->forProductVariant($this->tea, new \DateInterval('P1M'));
        self::assertCount(1, $committed, 'Only the tea added again, not the removed one.');
        self::assertSame(2, $committed[0]->quantity);
    }

    /** A T-shirt sold in M at $20.00, L at $25.00 and XL at $30.00; M and L have a monthly plan with 10% off, XL a quarterly one. */
    private function tShirtSizes(): array
    {
        $medium = $this->variantOfANewProduct('T-Shirt', 2000);
        $product = $medium->getProduct();
        self::assertInstanceOf(ProductInterface::class, $product);
        /** @var ProductContext $products */
        $products = self::getContainer()->get('sylius.behat.context.setup.product');
        $products->theProductHasVariantPricedAt($product, 'T-Shirt L', 2500);
        $products->theProductHasVariantPricedAt($product, 'T-Shirt XL', 3000);
        $large = $this->variantOf($product, 'T_SHIRT_L');
        $extraLarge = $this->variantOf($product, 'T_SHIRT_XL');

        $this->plan($medium, 'TSHIRT_M_MONTHLY', 1, SubscriptionIntervalUnit::Month, 10);
        $this->plan($large, 'TSHIRT_L_MONTHLY', 1, SubscriptionIntervalUnit::Month, 10);
        $this->plan($extraLarge, 'TSHIRT_XL_QUARTERLY', 3, SubscriptionIntervalUnit::Month, 10);
        $this->entityManager()->flush();

        return [$medium, $large, $extraLarge];
    }

    private function variantOf(ProductInterface $product, string $code): ProductVariantInterface
    {
        foreach ($product->getVariants() as $variant) {
            if ($code === $variant->getCode()) {
                self::assertInstanceOf(ProductVariantInterface::class, $variant);

                return $variant;
            }
        }

        self::fail(\sprintf('The product has no variant "%s".', $code));
    }

    private function planOf(ProductVariantInterface $variant, string $code): SubscriptionPlanInterface
    {
        $plan = self::getContainer()->get('jpm_martin_sylius_subscription.repository.subscription_plan')->findOneBy(['productVariant' => $variant, 'code' => $code]);
        self::assertInstanceOf(SubscriptionPlanInterface::class, $plan);

        return $plan;
    }

    /** The paid subscription of one item of the variant, on the plan. */
    private function subscriptionOf(ProductVariantInterface $variant, string $planCode): SubscriptionInterface
    {
        $this->itIsNow('2027-01-01 09:00');
        $order = $this->cart();
        $this->addLine($order, $variant, 1, $this->planOf($variant, $planCode));
        $this->placeWithConsent($order);
        $this->pay($order);
        $this->collector()->clear();

        return $this->subscriptionsByPlan()[$planCode];
    }

    private function coffeeSubscription(): SubscriptionInterface
    {
        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedCoffeeOrder());
        $this->collector()->clear();

        return $this->subscriptionsByPlan()['COFFEE_MONTHLY'];
    }

    private function refreshed(SubscriptionInterface $subscription): SubscriptionInterface
    {
        foreach ($this->storedSubscriptions() as $stored) {
            if ($stored->getId() === $subscription->getId()) {
                return $stored;
            }
        }

        self::fail('The subscription is gone.');
    }

    private function itemOf(SubscriptionInterface $subscription, ProductVariantInterface $variant): SubscriptionItemInterface
    {
        foreach ($subscription->getItems() as $item) {
            if ($variant->getId() === $item->getProductVariant()?->getId()) {
                return $item;
            }
        }

        self::fail(\sprintf('The subscription has no item of "%s".', $variant->getCode()));
    }

    private function cycle(SubscriptionInterface $subscription, int $number): SubscriptionCycleInterface
    {
        foreach ($this->storedCycles($subscription) as $cycle) {
            if ($number === $cycle->getNumber()) {
                return $cycle;
            }
        }

        self::fail(\sprintf('The subscription has no cycle %d.', $number));
    }

    private function renewalOrder(SubscriptionInterface $subscription, int $number): OrderInterface
    {
        $order = $this->cycle($subscription, $number)->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);
        $this->entityManager()->refresh($order);

        return $order;
    }

    private function lineOf(OrderInterface $order, ProductVariantInterface $variant): OrderItemInterface
    {
        foreach ($order->getItems() as $line) {
            if ($variant->getId() === $line->getVariant()?->getId()) {
                return $line;
            }
        }

        self::fail(\sprintf('The order has no line of "%s".', $variant->getCode()));
    }

    /**
     * By code: a failed comparison of entities would print the whole object graph.
     *
     * @param list<SubscriptionItemOffer> $offers
     *
     * @return list<string>
     */
    private function variants(array $offers): array
    {
        return array_map(static fn (SubscriptionItemOffer $offer): string => (string) $offer->variant->getCode(), $offers);
    }

    /** @return list<string> */
    private function frequencyKeys(SubscriptionInterface $subscription): array
    {
        /** @var SubscriptionFrequencyChangerInterface $changer */
        $changer = self::getContainer()->get(SubscriptionFrequencyChangerInterface::class);

        return array_map(static fn (SubscriptionInterval $interval): string => $interval->key(), $changer->frequenciesToChangeTo($subscription));
    }

    private function editor(): SubscriptionItemEditorInterface
    {
        $editor = self::getContainer()->get(SubscriptionItemEditorInterface::class);
        self::assertInstanceOf(SubscriptionItemEditorInterface::class, $editor);

        return $editor;
    }

    private function collector(): EventCollector
    {
        $collector = self::getContainer()->get('jpm_martin_sylius_subscription.test.event_collector');
        self::assertInstanceOf(EventCollector::class, $collector);

        return $collector;
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return $translator;
    }
}
