<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Pricing;

use JpmMartin\SyliusSubscriptionPlugin\Command\UpdateSubscriptionPrices;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionPriceChanged;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionPriceIncreaseAnnounced;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionFrequencyChangerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Pricing\ConfiguredPriceIncreaseAcceptancePolicy;
use JpmMartin\SyliusSubscriptionPlugin\Pricing\PendingPriceApplier;
use JpmMartin\SyliusSubscriptionPlugin\Pricing\PriceUpdateTarget;
use JpmMartin\SyliusSubscriptionPlugin\Pricing\SubscriptionPriceUpdaterInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionInterval;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Event\EventCollector;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

/**
 * Monthly Coffee subscriptions activated on 1 January, renewing on the 1st: Coffee sells at $100.00
 * and its monthly plan takes 10% off, so each renewal costs $90.00. Increases get the default 30 days
 * of notice.
 */
final class UpdatingSubscriptionPricesTest extends LifecycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedCoffeeOrder());
        $this->collector()->clear();
    }

    public function testThePreviewCountsTheSubscriptionsThatGoUpAndThoseAlreadyAtTheNewPrice(): void
    {
        $this->pay($this->placedCoffeeOrder());
        $this->pay($this->placedCoffeeOrder());
        $this->pay($this->placedCoffeeOrder());
        $subscriptions = $this->storedSubscriptions();
        self::assertCount(4, $subscriptions);
        $this->onlyItemOf($subscriptions[1])->setUnitPrice(9900);
        $this->apply($subscriptions[3], SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_CANCEL);
        $this->coffeeCostsNow(11000);

        $preview = $this->updater()->preview($this->coffeeMonthlyTarget());

        self::assertSame(2, $preview->increases);
        self::assertSame(0, $preview->decreases);
        self::assertSame(1, $preview->unchanged, 'The one already at $99.00.');
        self::assertCount(3, $preview->subscriptionIds, 'Not the cancelled one.');
        self::assertNotContains((int) $subscriptions[3]->getId(), $preview->subscriptionIds);
    }

    public function testADecreaseAppliesAtOnceToTheNextRenewalWithoutNotice(): void
    {
        $this->coffeeCostsNow(8000);
        $this->itIsNow('2027-01-10 09:00');
        $this->update($this->subscription());

        $item = $this->onlyItemOf($this->subscription());
        self::assertSame(7200, $item->getUnitPrice(), '$80.00 less 10%.');
        self::assertFalse($item->hasPendingPrice());
        self::assertEquals(
            [new SubscriptionPriceChanged((int) $this->subscription()->getId(), 9000, 7200)],
            $this->collector()->events(SubscriptionPriceChanged::class),
        );
        self::assertCount(0, $this->collector()->events(SubscriptionPriceIncreaseAnnounced::class));

        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();
        self::assertSame(7200, $this->coffeeLineOf($this->renewalOrder(2))->getUnitPrice());
    }

    public function testAnIncreaseIsAnnouncedAndAppliesFromTheFirstRenewalThirtyDaysOn(): void
    {
        $this->coffeeCostsNow(11000);
        $this->itIsNow('2027-01-10 09:00');
        $this->update($this->subscription());

        $subscriptionId = (int) $this->subscription()->getId();
        $item = $this->onlyItemOf($this->subscription());
        self::assertSame(9000, $item->getUnitPrice());
        self::assertSame(9900, $item->getPendingUnitPrice());
        self::assertSame('2027-02-09 09:00', $item->getPendingPriceFrom()?->format('Y-m-d H:i'));
        self::assertEquals(
            [new SubscriptionPriceIncreaseAnnounced($subscriptionId, 9000, 9900, new \DateTimeImmutable('2027-02-09 09:00'), false)],
            $this->collector()->events(SubscriptionPriceIncreaseAnnounced::class),
        );
        self::assertCount(0, $this->collector()->events(SubscriptionPriceChanged::class));

        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();
        self::assertSame(9000, $this->coffeeLineOf($this->renewalOrder(2))->getUnitPrice(), 'Within the notice.');
        self::assertCount(0, $this->collector()->events(SubscriptionPriceChanged::class));

        $this->itIsNow('2027-03-01 09:00');
        $this->runTheCycleCommand();
        self::assertSame(9900, $this->coffeeLineOf($this->renewalOrder(3))->getUnitPrice());
        self::assertFalse($this->onlyItemOf($this->subscription())->hasPendingPrice());
        self::assertEquals(
            [new SubscriptionPriceChanged($subscriptionId, 9000, 9900)],
            $this->collector()->events(SubscriptionPriceChanged::class),
        );
    }

    public function testASecondIncreaseReplacesThePendingOneAndStartsTheNoticeAgain(): void
    {
        $this->coffeeCostsNow(11000);
        $this->itIsNow('2027-01-11 09:00');
        $this->update($this->subscription());
        self::assertSame('2027-02-10 09:00', $this->onlyItemOf($this->subscription())->getPendingPriceFrom()?->format('Y-m-d H:i'));

        $this->coffeeCostsNow(12000);
        $this->itIsNow('2027-01-20 09:00');
        $this->update($this->subscription());

        $item = $this->onlyItemOf($this->subscription());
        self::assertSame(10800, $item->getPendingUnitPrice());
        self::assertSame('2027-02-19 09:00', $item->getPendingPriceFrom()?->format('Y-m-d H:i'));
    }

    public function testUpdatingAgainToThePendingPriceKeepsItsNotice(): void
    {
        $this->coffeeCostsNow(11000);
        $this->itIsNow('2027-01-11 09:00');
        $this->update($this->subscription());
        $this->collector()->clear();

        $this->itIsNow('2027-01-20 09:00');
        self::assertSame(1, $this->updater()->preview($this->coffeeMonthlyTarget())->unchanged);
        $this->update($this->subscription());

        self::assertSame('2027-02-10 09:00', $this->onlyItemOf($this->subscription())->getPendingPriceFrom()?->format('Y-m-d H:i'));
        self::assertCount(0, $this->collector()->events(SubscriptionPriceIncreaseAnnounced::class));
    }

    public function testTheCurrentPriceWithdrawsAPendingIncrease(): void
    {
        $this->coffeeCostsNow(11000);
        $this->itIsNow('2027-01-11 09:00');
        $this->update($this->subscription());

        $this->coffeeCostsNow(10000);
        self::assertSame(1, $this->updater()->preview($this->coffeeMonthlyTarget())->decreases);
        $this->update($this->subscription());

        $item = $this->onlyItemOf($this->subscription());
        self::assertSame(9000, $item->getUnitPrice());
        self::assertFalse($item->hasPendingPrice());
    }

    public function testChangingTheFrequencyFreezesTodaysPriceInsteadOfThePendingIncrease(): void
    {
        $this->plan($this->coffee, 'COFFEE_QUARTERLY', 3, SubscriptionIntervalUnit::Month, 0);
        $this->entityManager()->flush();
        $this->coffeeCostsNow(11000);
        $this->itIsNow('2027-01-11 09:00');
        $this->update($this->subscription());

        /** @var SubscriptionFrequencyChangerInterface $frequencyChanger */
        $frequencyChanger = self::getContainer()->get(SubscriptionFrequencyChangerInterface::class);
        $frequencyChanger->change($this->subscription(), new SubscriptionInterval(3, SubscriptionIntervalUnit::Month));
        $this->entityManager()->flush();

        $item = $this->onlyItemOf($this->subscription());
        self::assertSame(11000, $item->getUnitPrice(), 'Today\'s price, with the quarterly plan\'s discount of none.');
        self::assertFalse($item->hasPendingPrice());
    }

    public function testARenewalOrderAlreadyPlacedKeepsItsPrice(): void
    {
        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $this->cycle(2)->getState());

        $this->coffeeCostsNow(8000);
        $this->itIsNow('2027-02-01 12:00');
        $this->update($this->subscription());

        $this->itIsNow('2027-02-02 09:00');
        $this->runTheCycleCommand();
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $this->cycle(2)->getState());
        self::assertSame(9000, $this->coffeeLineOf($this->renewalOrder(2))->getUnitPrice(), 'The order awaiting its retry keeps its price.');

        $this->itIsNow('2027-03-01 09:00');
        $this->runTheCycleCommand();
        self::assertSame(7200, $this->coffeeLineOf($this->renewalOrder(3))->getUnitPrice());
    }

    public function testACancelledSubscriptionIsNotRepriced(): void
    {
        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_CANCEL);
        $this->entityManager()->flush();
        $this->coffeeCostsNow(8000);

        $this->update($this->subscription());

        self::assertSame(9000, $this->onlyItemOf($this->subscription())->getUnitPrice());
        self::assertCount(0, $this->collector()->events(SubscriptionPriceChanged::class));
    }

    public function testAnIncreaseAcceptedInTimeIsChargedWhenItApplies(): void
    {
        $this->requireAcceptance();
        $this->coffeeCostsNow(11000);
        $this->itIsNow('2027-01-10 09:00');
        $this->update($this->subscription());
        self::assertTrue($this->collector()->events(SubscriptionPriceIncreaseAnnounced::class)[0]->acceptanceRequired);
        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();

        $this->itIsNow('2027-02-20 09:00');
        $this->applier()->accept($this->subscription());
        $this->entityManager()->flush();
        self::assertEquals(new \DateTimeImmutable('2027-02-20 09:00'), $this->subscription()->getPriceIncreaseAcceptedAt());

        $this->itIsNow('2027-03-01 09:00');
        $this->runTheCycleCommand();
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $this->subscription()->getState());
        self::assertSame(9900, $this->coffeeLineOf($this->renewalOrder(3))->getUnitPrice());
    }

    public function testAnotherIncreaseAsksForTheAcceptanceAgain(): void
    {
        $this->requireAcceptance();
        $this->coffeeCostsNow(11000);
        $this->itIsNow('2027-01-10 09:00');
        $this->update($this->subscription());
        $this->applier()->accept($this->subscription());
        $this->entityManager()->flush();
        self::assertFalse($this->applier()->awaitsAcceptance($this->subscription()));

        $this->coffeeCostsNow(12000);
        $this->itIsNow('2027-01-20 09:00');
        $this->update($this->subscription());

        self::assertNull($this->subscription()->getPriceIncreaseAcceptedAt());
        self::assertTrue($this->applier()->awaitsAcceptance($this->subscription()));
    }

    public function testAnIncreaseNotAcceptedPausesTheSubscriptionWhichResumesOnlyOnceItIsAccepted(): void
    {
        $this->requireAcceptance();
        $this->coffeeCostsNow(11000);
        $this->itIsNow('2027-01-10 09:00');
        $this->update($this->subscription());
        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();
        $ordersBefore = $this->countOrders();

        $this->itIsNow('2027-03-01 09:00');
        $this->runTheCycleCommand();

        self::assertSame(SubscriptionInterface::STATE_PAUSED, $this->subscription()->getState());
        self::assertSame(SubscriptionCycleInterface::STATE_CANCELLED, $this->cycle(3)->getState());
        self::assertSame($ordersBefore, $this->countOrders(), 'No renewal order was placed.');
        self::assertSame(9900, $this->onlyItemOf($this->subscription())->getPendingUnitPrice(), 'Still pending.');
        self::assertFalse($this->stateMachine()->can($this->subscription(), SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_RESUME));

        $this->itIsNow('2027-03-05 09:00');
        $subscription = $this->subscription();
        $this->applier()->accept($subscription);
        self::assertTrue($this->stateMachine()->can($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_RESUME));
        $this->stateMachine()->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_RESUME);
        $this->entityManager()->flush();

        $this->itIsNow('2027-04-01 09:00');
        $this->runTheCycleCommand();
        self::assertSame(9900, $this->coffeeLineOf($this->renewalOrder(4))->getUnitPrice());
    }

    private function requireAcceptance(): void
    {
        self::getContainer()->set('jpm_martin_sylius_subscription.pricing.acceptance_policy', new ConfiguredPriceIncreaseAcceptancePolicy('required'));
    }

    private function update(SubscriptionInterface $subscription): void
    {
        /** @var MessageBusInterface $commandBus */
        $commandBus = self::getContainer()->get('sylius.command_bus');
        $target = $this->coffeeMonthlyTarget();
        $commandBus->dispatch(new UpdateSubscriptionPrices((int) $subscription->getId(), $target->type, $target->id));
        $this->entityManager()->clear();
    }

    private function coffeeMonthlyTarget(): PriceUpdateTarget
    {
        return new PriceUpdateTarget(PriceUpdateTarget::PLAN, (int) $this->coffeeMonthly->getId());
    }

    private function coffeeCostsNow(int $price): void
    {
        $channelPricing = $this->entityManager()->getRepository(ChannelPricingInterface::class)->findOneBy(['productVariant' => $this->coffee->getId()]);
        self::assertInstanceOf(ChannelPricingInterface::class, $channelPricing);
        $channelPricing->setPrice($price);
        $this->entityManager()->flush();
    }

    private function subscription(): SubscriptionInterface
    {
        return $this->storedSubscriptions()[0];
    }

    private function cycle(int $number): SubscriptionCycleInterface
    {
        foreach ($this->storedCycles($this->subscription()) as $cycle) {
            if ($number === $cycle->getNumber()) {
                return $cycle;
            }
        }

        self::fail(\sprintf('The subscription has no cycle %d.', $number));
    }

    private function renewalOrder(int $number): OrderInterface
    {
        $order = $this->cycle($number)->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);
        $this->entityManager()->refresh($order);

        return $order;
    }

    private function coffeeLineOf(OrderInterface $order): OrderItemInterface
    {
        $line = $order->getItems()->first();
        self::assertInstanceOf(OrderItemInterface::class, $line);
        self::assertSame($this->coffee->getId(), $line->getVariant()?->getId());

        return $line;
    }

    private function countOrders(): int
    {
        return (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM sylius_order');
    }

    private function updater(): SubscriptionPriceUpdaterInterface
    {
        $updater = self::getContainer()->get(SubscriptionPriceUpdaterInterface::class);
        self::assertInstanceOf(SubscriptionPriceUpdaterInterface::class, $updater);

        return $updater;
    }

    private function applier(): PendingPriceApplier
    {
        $applier = self::getContainer()->get(PendingPriceApplier::class);
        self::assertInstanceOf(PendingPriceApplier::class, $applier);

        return $applier;
    }

    private function stateMachine(): StateMachineInterface
    {
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');
        self::assertInstanceOf(StateMachineInterface::class, $stateMachine);

        return $stateMachine;
    }

    private function collector(): EventCollector
    {
        $collector = self::getContainer()->get('jpm_martin_sylius_subscription.test.event_collector');
        self::assertInstanceOf(EventCollector::class, $collector);

        return $collector;
    }
}
