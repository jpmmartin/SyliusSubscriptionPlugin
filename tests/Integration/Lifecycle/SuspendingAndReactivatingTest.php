<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionCalendarInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

final class SuspendingAndReactivatingTest extends LifecycleTestCase
{
    public function testASuspendedSubscriptionHasNoOpenCycle(): void
    {
        $subscription = $this->monthlyCoffeeActivatedOn('2027-01-01 09:00');

        $this->itIsNow('2027-01-15 09:00');
        $this->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_SUSPEND);
        $this->entityManager()->flush();

        self::assertSame(SubscriptionInterface::STATE_SUSPENDED, $this->subscriptionsByPlan()['COFFEE_MONTHLY']->getState());
        self::assertSame([1 => 'paid', 2 => 'cancelled'], $this->cycleStates($subscription));
    }

    public function testASubscriptionReactivatedAfterThreeUnpaidCyclesRenewsOnTheFirstDateOfItsCalendarAfterTodayWithItsFailuresCleared(): void
    {
        $this->monthlyCoffeeActivatedOn('2027-01-01 09:00');
        foreach (['02', '03', '04'] as $month) {
            foreach (['01', '02', '04', '08'] as $day) {
                $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
                $this->itIsNow(\sprintf('2027-%s-%s 09:00', $month, $day));
                $this->runTheCycleCommand();
            }
        }
        $subscription = $this->subscriptionsByPlan()['COFFEE_MONTHLY'];
        self::assertSame(SubscriptionInterface::STATE_SUSPENDED, $subscription->getState());
        self::assertSame(3, $subscription->getConsecutiveFailedCycles());

        $this->itIsNow('2027-05-10 12:00');
        $this->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_REACTIVATE);
        $this->entityManager()->flush();

        $subscription = $this->subscriptionsByPlan()['COFFEE_MONTHLY'];
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState());
        self::assertSame(0, $subscription->getConsecutiveFailedCycles());
        self::assertSame([1 => 'paid', 2 => 'failed', 3 => 'failed', 4 => 'failed', 5 => 'scheduled'], $this->cycleStates($subscription));
        self::assertSame('2027-06-01 09:00', $this->storedCycles($subscription)[4]->getScheduledAt()?->format('Y-m-d H:i'));
    }

    public function testAReactivatedCalendarKeepsItsDayOfTheMonth(): void
    {
        $subscription = $this->monthlyCoffeeActivatedOn('2027-01-31 09:00');

        $this->itIsNow('2027-02-10 09:00');
        $this->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_SUSPEND);
        $this->entityManager()->flush();

        $this->itIsNow('2027-04-05 09:00');
        $this->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_REACTIVATE);
        $this->entityManager()->flush();

        $cycles = $this->storedCycles($subscription);
        self::assertSame('2027-04-30 09:00', $cycles[2]->getScheduledAt()?->format('Y-m-d H:i'), 'The first date of its calendar after 5 April.');

        /** @var SubscriptionCalendarInterface $calendar */
        $calendar = self::getContainer()->get('jpm_martin_sylius_subscription.schedule.calendar');
        self::assertSame('2027-05-31 09:00', $calendar->dateOfCycle($subscription, $cycles[2]->getNumber() + 1)->format('Y-m-d H:i'));
    }

    private function monthlyCoffeeActivatedOn(string $dateTime): SubscriptionInterface
    {
        $this->itIsNow($dateTime);
        $this->pay($this->placedCoffeeOrder());

        return $this->subscriptionsByPlan()['COFFEE_MONTHLY'];
    }

    /** @return array<int, string> */
    private function cycleStates(SubscriptionInterface $subscription): array
    {
        $states = [];
        foreach ($this->storedCycles($subscription) as $cycle) {
            self::assertInstanceOf(SubscriptionCycleInterface::class, $cycle);
            $states[$cycle->getNumber()] = $cycle->getState();
        }

        return $states;
    }
}
