<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Event;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalCancelled;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalChargeDeclined;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalFailed;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalHeld;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalOrderPlaced;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalPaid;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalRetried;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionActivated;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionCancelled;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionCompleted;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionEventInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionFrequencyChanged;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionReactivated;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionSuspended;
use JpmMartin\SyliusSubscriptionPlugin\Gate\GateDecision;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionCycleRetrierInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionFrequencyChangerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionInterval;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Sylius\Component\Order\OrderTransitions;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Event\EventCollector;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Gate\ScriptedCycleGate;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

/** A monthly Coffee subscription activated on 1 January, whose second cycle is due on 1 February. */
final class LifecycleEventsTest extends LifecycleTestCase
{
    private int $subscriptionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedCoffeeOrder());
        $this->subscriptionId = (int) $this->subscription()->getId();
    }

    public function testActivatingASubscriptionPublishesItWithTheSubscription(): void
    {
        self::assertEquals([new SubscriptionActivated($this->subscriptionId)], $this->collector()->events());
    }

    public function testSuspendingReactivatingAndCancellingPublishTheSubscriptionsMomentsAndItsCancelledCycles(): void
    {
        $this->collector()->clear();
        [, $open] = $this->storedCycles($this->subscription());
        $openId = (int) $open->getId();

        $this->transition(SubscriptionTransitions::TRANSITION_SUSPEND);
        $this->transition(SubscriptionTransitions::TRANSITION_REACTIVATE);
        [, , $next] = $this->storedCycles($this->subscription());
        $this->transition(SubscriptionTransitions::TRANSITION_CANCEL);

        self::assertEquals([
            new SubscriptionSuspended($this->subscriptionId),
            new RenewalCancelled($this->subscriptionId, $openId, 2, null, null),
            new SubscriptionReactivated($this->subscriptionId),
            new SubscriptionCancelled($this->subscriptionId),
            new RenewalCancelled($this->subscriptionId, (int) $next->getId(), 3, null, null),
        ], $this->collector()->events());
    }

    public function testARenewalChargedByTheCommandPublishesItsOrderThenItsPayment(): void
    {
        $this->collector()->clear();
        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();

        [, $second] = $this->storedCycles($this->subscription());
        $cycleId = (int) $second->getId();
        $orderId = (int) $second->getOrder()?->getId();
        self::assertEquals([
            new RenewalOrderPlaced($this->subscriptionId, $cycleId, 2, $orderId),
            new RenewalPaid($this->subscriptionId, $cycleId, 2, $orderId),
        ], $this->collector()->events());
    }

    public function testASubscriptionWhoseItemsAreUsedUpPublishesThatItEnded(): void
    {
        $this->coffeeMonthly->setMaxCycles(2);
        $this->entityManager()->flush();
        $this->collector()->clear();
        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();

        self::assertSame(
            [RenewalOrderPlaced::class, RenewalPaid::class, SubscriptionCompleted::class],
            array_map(static fn (SubscriptionEventInterface $event): string => $event::class, $this->collector()->events()),
        );
        self::assertEquals([new SubscriptionCompleted($this->subscriptionId)], $this->collector()->events(SubscriptionCompleted::class));
    }

    public function testACycleAGateHoldsIsPublishedWithItsDeadlineAndReason(): void
    {
        $this->gate()->decide(GateDecision::wait(new \DateTimeImmutable('2027-02-06 09:00'), 'Waiting for the prescriber.'));
        $this->collector()->clear();
        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();

        [, $second] = $this->storedCycles($this->subscription());
        self::assertEquals(
            [new RenewalHeld($this->subscriptionId, (int) $second->getId(), 2, new \DateTimeImmutable('2027-02-06 09:00'), 'Waiting for the prescriber.')],
            $this->collector()->events(),
        );
    }

    public function testACycleAGateRejectsIsPublishedAsFailedWithoutAnOrder(): void
    {
        $this->gate()->decide(GateDecision::reject('The prescription has expired.'));
        $this->collector()->clear();
        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();

        [, $second] = $this->storedCycles($this->subscription());
        self::assertEquals(
            [new RenewalFailed($this->subscriptionId, (int) $second->getId(), 2, null, 'The prescription has expired.')],
            $this->collector()->events(),
        );
    }

    public function testAnAdministratorsRetryIsPublishedThenItsPaymentWithTheNewOrder(): void
    {
        foreach (['2027-02-01 09:00', '2027-02-02 09:00', '2027-02-04 09:00', '2027-02-08 09:00'] as $dateTime) {
            $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
            $this->itIsNow($dateTime);
            $this->runTheCycleCommand();
        }
        [, $failed] = $this->storedCycles($this->subscription());
        $cycleId = (int) $failed->getId();
        $failedOrderId = (int) $failed->getOrder()?->getId();
        self::assertEquals([new RenewalFailed($this->subscriptionId, $cycleId, 2, $failedOrderId, 'Insufficient funds.')], $this->collector()->events(RenewalFailed::class));

        $this->collector()->clear();
        $this->itIsNow('2027-02-10 11:00');
        $retrier = self::getContainer()->get(SubscriptionCycleRetrierInterface::class);
        self::assertInstanceOf(SubscriptionCycleRetrierInterface::class, $retrier);
        $retrier->retry($failed);
        $this->entityManager()->flush();

        [, $retried] = $this->storedCycles($this->subscription());
        $newOrderId = (int) $retried->getOrder()?->getId();
        self::assertNotSame($failedOrderId, $newOrderId);
        self::assertEquals([
            new RenewalRetried($this->subscriptionId, $cycleId, 2),
            new RenewalPaid($this->subscriptionId, $cycleId, 2, $newOrderId),
        ], $this->collector()->events());
    }

    public function testARenewalOrderTheAdministratorCancelsPublishesTheCycleCancelledWithItsOrder(): void
    {
        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();
        [, $second] = $this->storedCycles($this->subscription());
        $order = $second->getOrder();
        self::assertNotNull($order);
        $this->collector()->clear();

        $this->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);
        $this->entityManager()->flush();

        self::assertEquals(
            [new RenewalCancelled($this->subscriptionId, (int) $second->getId(), 2, (int) $order->getId(), null)],
            $this->collector()->events(),
        );
    }

    public function testADeclineThatWillBeRetriedIsPublishedWithTheNextAttemptTheReasonAndTheCode(): void
    {
        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.', 'insufficient_funds');
        $this->collector()->clear();
        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();

        [, $second] = $this->storedCycles($this->subscription());
        $cycleId = (int) $second->getId();
        $orderId = (int) $second->getOrder()?->getId();
        self::assertEquals([
            new RenewalOrderPlaced($this->subscriptionId, $cycleId, 2, $orderId),
            new RenewalChargeDeclined($this->subscriptionId, $cycleId, 2, $orderId, new \DateTimeImmutable('2027-02-02 09:00'), 'Insufficient funds.', 'insufficient_funds'),
        ], $this->collector()->events());
    }

    public function testTheLastRetryDeclinedIsPublishedAsAFailedCycleAndNotAsADecline(): void
    {
        foreach (['2027-02-01 09:00', '2027-02-02 09:00', '2027-02-04 09:00'] as $dateTime) {
            $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
            $this->itIsNow($dateTime);
            $this->runTheCycleCommand();
        }
        self::assertCount(3, $this->collector()->events(RenewalChargeDeclined::class), 'The first charge and two retries, each tried again.');
        $this->collector()->clear();

        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
        $this->itIsNow('2027-02-08 09:00');
        $this->runTheCycleCommand();

        self::assertSame([RenewalFailed::class], array_map(static fn (SubscriptionEventInterface $event): string => $event::class, $this->collector()->events()));
    }

    public function testAnAdministratorsRetryThatIsDeclinedIsNotPublishedAsADecline(): void
    {
        foreach (['2027-02-01 09:00', '2027-02-02 09:00', '2027-02-04 09:00', '2027-02-08 09:00'] as $dateTime) {
            $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
            $this->itIsNow($dateTime);
            $this->runTheCycleCommand();
        }
        [, $failed] = $this->storedCycles($this->subscription());
        $this->collector()->clear();

        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Still insufficient.');
        $this->itIsNow('2027-02-10 11:00');
        $retrier = self::getContainer()->get(SubscriptionCycleRetrierInterface::class);
        self::assertInstanceOf(SubscriptionCycleRetrierInterface::class, $retrier);
        $retrier->retry($failed);
        $this->entityManager()->flush();

        self::assertSame(
            [RenewalRetried::class, RenewalFailed::class],
            array_map(static fn (SubscriptionEventInterface $event): string => $event::class, $this->collector()->events()),
        );
    }

    public function testChangingTheFrequencyIsPublishedWithTheNewInterval(): void
    {
        // Tea, on the monthly plan, may move to its plan of every two weeks.
        $order = $this->cart();
        $this->addLine($order, $this->tea, 1, $this->teaMonthly);
        $this->placeWithConsent($order);
        $this->pay($order);
        [, $tea] = $this->storedSubscriptions();
        $this->collector()->clear();

        $changer = self::getContainer()->get(SubscriptionFrequencyChangerInterface::class);
        self::assertInstanceOf(SubscriptionFrequencyChangerInterface::class, $changer);
        $changer->change($tea, new SubscriptionInterval(2, SubscriptionIntervalUnit::Week));
        $this->entityManager()->flush();

        self::assertEquals([new SubscriptionFrequencyChanged((int) $tea->getId(), 2, 'week')], $this->collector()->events());
    }

    private function transition(string $transition): void
    {
        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, $transition);
        $this->entityManager()->flush();
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

    private function gate(): ScriptedCycleGate
    {
        $gate = self::getContainer()->get('jpm_martin_sylius_subscription.test.cycle_gate');
        self::assertInstanceOf(ScriptedCycleGate::class, $gate);

        return $gate;
    }
}
