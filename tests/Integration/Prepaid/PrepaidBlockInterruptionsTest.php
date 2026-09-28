<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Prepaid;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionCycleRetrierInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionRenewalSkipperInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

/**
 * Coffee at $90.00 a delivery on its monthly plan, charged by blocks of three, subscribed to on
 * 1 January: February and March are delivered, April charges the next block.
 */
final class PrepaidBlockInterruptionsTest extends LifecycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->coffeeMonthly->setDeliveriesPerCharge(3);
        $this->entityManager()->flush();
        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedCoffeeOrder());
    }

    public function testABlockThatCannotBeChargedIsNotDeliveredAndTheNextChargeComesAfterIt(): void
    {
        $this->renewOn('2027-02-01', '2027-03-01');
        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Stolen card.', 'stolen_card');
        $this->renewOn('2027-04-01');

        $cycles = $this->storedCycles($this->subscription());
        self::assertSame(SubscriptionCycleInterface::STATE_FAILED, $cycles[3]->getState());
        self::assertSame(0, $this->subscription()->getPrepaidDeliveriesLeft());
        self::assertCount(5, $cycles, 'No delivery in May or June.');
        self::assertTrue($cycles[4]->isCharging());
        self::assertSame('2027-07-01 09:00', $cycles[4]->getScheduledAt()?->format('Y-m-d H:i'));
    }

    public function testABlockPaidLateIsDeliveredFromTheCycleScheduledMeanwhile(): void
    {
        $this->renewOn('2027-02-01', '2027-03-01');
        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Stolen card.', 'stolen_card');
        $this->renewOn('2027-04-01');

        $this->itIsNow('2027-04-10 09:00');
        /** @var SubscriptionCycleRetrierInterface $retrier */
        $retrier = self::getContainer()->get(SubscriptionCycleRetrierInterface::class);
        $retrier->retry($this->storedCycles($this->subscription())[3]);
        $this->entityManager()->flush();

        $subscription = $this->subscription();
        $cycles = $this->storedCycles($subscription);
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $cycles[3]->getState());
        self::assertSame(2, $subscription->getPrepaidDeliveriesLeft());
        self::assertFalse($cycles[4]->isCharging(), 'July delivers the block paid late instead of charging.');
    }

    public function testPausingKeepsTheDeliveriesLeftAndResumingDeliversThemBeforeTheNextCharge(): void
    {
        $this->itIsNow('2027-01-10 09:00');
        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_PAUSE);
        $this->entityManager()->flush();
        self::assertSame(2, $this->subscription()->getPrepaidDeliveriesLeft(), 'February and March are paid for.');

        $this->itIsNow('2027-02-10 09:00');
        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_RESUME);
        $this->entityManager()->flush();
        $open = $this->openCycle();
        self::assertFalse($open->isCharging());
        self::assertSame('2027-03-01 09:00', $open->getScheduledAt()?->format('Y-m-d H:i'));

        $this->renewOn('2027-03-01');
        self::assertFalse($this->openCycle()->isCharging(), 'The second delivery paid for.');
        $this->renewOn('2027-04-01');

        self::assertSame(0, $this->subscription()->getPrepaidDeliveriesLeft());
        $open = $this->openCycle();
        self::assertTrue($open->isCharging(), 'Then comes the charge of the next block.');
        self::assertSame('2027-05-01 09:00', $open->getScheduledAt()?->format('Y-m-d H:i'));
        self::assertSame([], $this->scriptedGateway()->requests(), 'Nothing was charged since January.');
    }

    public function testADeliveryCanBeSkippedAndStaysPaidForButNotTheChargeOfABlock(): void
    {
        /** @var SubscriptionRenewalSkipperInterface $skipper */
        $skipper = self::getContainer()->get(SubscriptionRenewalSkipperInterface::class);
        $skipper->skip($this->subscription());
        $this->entityManager()->flush();

        self::assertSame(2, $this->subscription()->getPrepaidDeliveriesLeft(), 'The skipped delivery is still paid for.');
        $open = $this->openCycle();
        self::assertFalse($open->isCharging());
        self::assertSame('2027-03-01 09:00', $open->getScheduledAt()?->format('Y-m-d H:i'));

        $this->renewOn('2027-03-01', '2027-04-01');
        self::assertTrue($this->openCycle()->isCharging());
        self::assertFalse($skipper->canSkip($this->subscription()), 'Pausing is for a charge.');
    }

    public function testAnAdministratorCancelsAtOnceWithDeliveriesLeft(): void
    {
        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_CANCEL);
        $this->entityManager()->flush();

        $subscription = $this->subscription();
        self::assertSame(SubscriptionInterface::STATE_CANCELLED, $subscription->getState());
        self::assertSame(SubscriptionCycleInterface::STATE_CANCELLED, $this->storedCycles($subscription)[1]->getState(), 'February is not delivered.');
    }

    private function openCycle(): SubscriptionCycleInterface
    {
        foreach ($this->storedCycles($this->subscription()) as $cycle) {
            if (\in_array($cycle->getState(), [SubscriptionCycleInterface::STATE_SCHEDULED, SubscriptionCycleInterface::STATE_ON_HOLD], true)) {
                return $cycle;
            }
        }

        self::fail('No cycle is open.');
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
}
