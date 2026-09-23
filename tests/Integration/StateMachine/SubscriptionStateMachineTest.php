<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\StateMachine;

use JpmMartin\SyliusSubscriptionPlugin\Entity\Subscription;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItem;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;

final class SubscriptionStateMachineTest extends StateMachineGraphTestCase
{
    public function testANewSubscriptionIsPending(): void
    {
        self::assertSame(SubscriptionInterface::STATE_PENDING, (new Subscription())->getState());
    }

    protected static function graph(): string
    {
        return SubscriptionTransitions::GRAPH;
    }

    protected static function allowed(): array
    {
        return [
            SubscriptionInterface::STATE_PENDING => [
                SubscriptionTransitions::TRANSITION_ACTIVATE => SubscriptionInterface::STATE_ACTIVE,
                SubscriptionTransitions::TRANSITION_CANCEL => SubscriptionInterface::STATE_CANCELLED,
            ],
            SubscriptionInterface::STATE_ACTIVE => [
                SubscriptionTransitions::TRANSITION_SUSPEND => SubscriptionInterface::STATE_SUSPENDED,
                SubscriptionTransitions::TRANSITION_CANCEL => SubscriptionInterface::STATE_CANCELLED,
                SubscriptionTransitions::TRANSITION_COMPLETE => SubscriptionInterface::STATE_COMPLETED,
            ],
            SubscriptionInterface::STATE_SUSPENDED => [
                SubscriptionTransitions::TRANSITION_REACTIVATE => SubscriptionInterface::STATE_ACTIVE,
                SubscriptionTransitions::TRANSITION_CANCEL => SubscriptionInterface::STATE_CANCELLED,
            ],
            SubscriptionInterface::STATE_CANCELLED => [],
            SubscriptionInterface::STATE_COMPLETED => [],
        ];
    }

    /**
     * A monthly subscription of one item without a maximum, activated on 1 January, as the listeners of
     * its transitions expect one to be.
     */
    protected function subjectIn(string $state): object
    {
        $subscription = new Subscription();
        $subscription->addItem(new SubscriptionItem());
        $subscription->setBillingIntervalCount(1);
        $subscription->setBillingIntervalUnit(SubscriptionIntervalUnit::Month);
        $subscription->setScheduleAnchorAt(new \DateTimeImmutable('2027-01-01 09:00'));
        $subscription->setScheduleAnchorCycle(1);
        $subscription->setState($state);

        return $subscription;
    }

    protected function stateOf(object $subject): string
    {
        self::assertInstanceOf(SubscriptionInterface::class, $subject);

        return $subject->getState();
    }
}
