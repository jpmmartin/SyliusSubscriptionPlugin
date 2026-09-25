<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionFrequencyChangerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionInterval;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Sylius\Component\Core\Model\OrderInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

/** A monthly Coffee subscription activated on 1 January, paused and resumed by its customer. */
final class PausingAndResumingTest extends LifecycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedCoffeeOrder());
    }

    public function testAPausedSubscriptionCancelsItsOpenCycleAndPlacesNoOrderOnItsDate(): void
    {
        $this->itIsNow('2027-01-20 09:00');
        $this->transition(SubscriptionTransitions::TRANSITION_PAUSE);

        self::assertSame(SubscriptionInterface::STATE_PAUSED, $this->subscription()->getState());
        self::assertSame([1 => 'paid', 2 => 'cancelled'], $this->cycleStates());

        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();

        self::assertSame([1 => 'paid', 2 => 'cancelled'], $this->cycleStates(), 'No cycle is scheduled while paused.');
        self::assertNull($this->cycle(2)->getOrder());
        self::assertSame([], $this->scriptedGateway()->requests());
    }

    public function testAResumedSubscriptionRenewsOnTheFirstDateOfItsCalendarAfterThatDay(): void
    {
        $this->itIsNow('2027-01-20 09:00');
        $this->transition(SubscriptionTransitions::TRANSITION_PAUSE);

        $this->itIsNow('2027-03-10 12:00');
        $this->transition(SubscriptionTransitions::TRANSITION_RESUME);

        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $this->subscription()->getState());
        self::assertSame([1 => 'paid', 2 => 'cancelled', 3 => 'scheduled'], $this->cycleStates());
        self::assertSame('2027-04-01 09:00', $this->cycle(3)->getScheduledAt()?->format('Y-m-d H:i'));
    }

    public function testResumingBeforeTheAnchorOfAChangedFrequencyRenewsOnTheAnchor(): void
    {
        $this->itIsNow('2027-01-01 10:00');
        $order = $this->cart();
        $this->addLine($order, $this->tea, 1, $this->teaMonthly);
        $this->placeWithConsent($order);
        $this->pay($order);
        $this->itIsNow('2027-01-15 09:00');
        /** @var SubscriptionFrequencyChangerInterface $changer */
        $changer = self::getContainer()->get(SubscriptionFrequencyChangerInterface::class);
        $changer->change($this->subscriptionsByPlan()['TEA_MONTHLY'], new SubscriptionInterval(2, SubscriptionIntervalUnit::Week));
        $this->entityManager()->flush();
        $tea = $this->subscriptionsByPlan()['TEA_EVERY_TWO_WEEKS'];
        self::assertSame('2027-02-01 10:00', $tea->getScheduleAnchorAt()?->format('Y-m-d H:i'), 'The anchor is the open cycle, still to come.');

        $this->itIsNow('2027-01-20 09:00');
        $this->apply($tea, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_PAUSE);
        $this->entityManager()->flush();
        $this->itIsNow('2027-01-25 09:00');
        $this->apply($tea, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_RESUME);
        $this->entityManager()->flush();

        $dates = [];
        foreach ($this->storedCycles($this->subscriptionsByPlan()['TEA_EVERY_TWO_WEEKS']) as $cycle) {
            $dates[$cycle->getNumber()] = $cycle->getState() . ' ' . $cycle->getScheduledAt()?->format('Y-m-d');
        }
        self::assertSame([1 => 'paid 2027-01-01', 2 => 'cancelled 2027-02-01', 3 => 'scheduled 2027-02-01'], $dates, 'The first date after 25 January is the anchor itself.');
    }

    public function testResumingKeepsTheFailedCyclesInARowSoTheNextFailureStillSuspends(): void
    {
        $this->failTheCycleOf('02');
        $this->failTheCycleOf('03');
        self::assertSame(2, $this->subscription()->getConsecutiveFailedCycles());

        $this->itIsNow('2027-03-10 09:00');
        $this->transition(SubscriptionTransitions::TRANSITION_PAUSE);
        $this->itIsNow('2027-03-12 09:00');
        $this->transition(SubscriptionTransitions::TRANSITION_RESUME);
        self::assertSame(2, $this->subscription()->getConsecutiveFailedCycles(), 'Pausing does not change the payment method.');

        $this->failTheCycleOf('04');

        $subscription = $this->subscription();
        self::assertSame(SubscriptionInterface::STATE_SUSPENDED, $subscription->getState());
        self::assertSame(3, $subscription->getConsecutiveFailedCycles());
    }

    public function testPausingWhileTheRenewalOrderAwaitsItsRetryCancelsTheOrderAndTheRetryIsNotMade(): void
    {
        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $this->cycle(2)->getState());
        $order = $this->cycle(2)->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);

        $this->itIsNow('2027-02-01 12:00');
        $this->transition(SubscriptionTransitions::TRANSITION_PAUSE);

        self::assertSame(SubscriptionCycleInterface::STATE_CANCELLED, $this->cycle(2)->getState());
        self::assertSame(OrderInterface::STATE_CANCELLED, $this->cycle(2)->getOrder()?->getState());

        $this->itIsNow('2027-02-02 09:00');
        $this->runTheCycleCommand();

        self::assertSame(['capture'], $this->scriptedGateway()->requests(), 'The retry of 2 February is not made.');
    }

    /** What the customer's or the administrator's action does, in a fresh entity manager like a new request. */
    private function transition(string $transition): void
    {
        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, $transition);
        $this->entityManager()->flush();
        $this->entityManager()->clear();
    }

    /** The cycle of that month, declined on its first attempt and on each of its three retries. */
    private function failTheCycleOf(string $month): void
    {
        foreach (['01', '02', '04', '08'] as $day) {
            $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
            $this->itIsNow(\sprintf('2027-%s-%s 09:00', $month, $day));
            $this->runTheCycleCommand();
        }
    }

    private function subscription(): SubscriptionInterface
    {
        return $this->subscriptionsByPlan()['COFFEE_MONTHLY'];
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

    /** @return array<int, string> */
    private function cycleStates(): array
    {
        $states = [];
        foreach ($this->storedCycles($this->subscription()) as $cycle) {
            $states[$cycle->getNumber()] = $cycle->getState();
        }

        return $states;
    }
}
