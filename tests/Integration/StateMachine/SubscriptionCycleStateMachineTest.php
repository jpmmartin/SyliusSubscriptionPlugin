<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\StateMachine;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycle;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionCycleTransitions;

final class SubscriptionCycleStateMachineTest extends StateMachineGraphTestCase
{
    public function testANewCycleIsScheduled(): void
    {
        self::assertSame(SubscriptionCycleInterface::STATE_SCHEDULED, (new SubscriptionCycle())->getState());
    }

    protected static function graph(): string
    {
        return SubscriptionCycleTransitions::GRAPH;
    }

    protected static function allowed(): array
    {
        return [
            SubscriptionCycleInterface::STATE_SCHEDULED => [
                SubscriptionCycleTransitions::TRANSITION_HOLD => SubscriptionCycleInterface::STATE_ON_HOLD,
                SubscriptionCycleTransitions::TRANSITION_PLACE_ORDER => SubscriptionCycleInterface::STATE_AWAITING_PAYMENT,
                SubscriptionCycleTransitions::TRANSITION_FAIL => SubscriptionCycleInterface::STATE_FAILED,
                SubscriptionCycleTransitions::TRANSITION_CANCEL => SubscriptionCycleInterface::STATE_CANCELLED,
            ],
            SubscriptionCycleInterface::STATE_ON_HOLD => [
                SubscriptionCycleTransitions::TRANSITION_PLACE_ORDER => SubscriptionCycleInterface::STATE_AWAITING_PAYMENT,
                SubscriptionCycleTransitions::TRANSITION_FAIL => SubscriptionCycleInterface::STATE_FAILED,
                SubscriptionCycleTransitions::TRANSITION_CANCEL => SubscriptionCycleInterface::STATE_CANCELLED,
            ],
            SubscriptionCycleInterface::STATE_AWAITING_PAYMENT => [
                SubscriptionCycleTransitions::TRANSITION_PAY => SubscriptionCycleInterface::STATE_PAID,
                SubscriptionCycleTransitions::TRANSITION_FAIL => SubscriptionCycleInterface::STATE_FAILED,
                SubscriptionCycleTransitions::TRANSITION_CANCEL => SubscriptionCycleInterface::STATE_CANCELLED,
            ],
            SubscriptionCycleInterface::STATE_PAID => [],
            SubscriptionCycleInterface::STATE_FAILED => [
                SubscriptionCycleTransitions::TRANSITION_RETRY => SubscriptionCycleInterface::STATE_AWAITING_PAYMENT,
            ],
            SubscriptionCycleInterface::STATE_CANCELLED => [],
        ];
    }

    protected function subjectIn(string $state): object
    {
        $cycle = new SubscriptionCycle();
        $cycle->setState($state);

        return $cycle;
    }

    protected function stateOf(object $subject): string
    {
        self::assertInstanceOf(SubscriptionCycleInterface::class, $subject);

        return $subject->getState();
    }
}
