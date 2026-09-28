<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Cycle;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionSchedulerInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionCycleTransitions;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Webmozart\Assert\Assert;

/**
 * Closes a cycle whose order is paid for: its charge was paid, or it is a prepaid delivery its block
 * paid for. Each item it carried counts one more paid cycle, the run of failures ends, the deliveries
 * a charge paid for ahead are counted, and the schedule goes on.
 */
final class CyclePayer
{
    public function __construct(
        private readonly StateMachineInterface $stateMachine,
        private readonly SubscriptionSchedulerInterface $scheduler,
    ) {
    }

    public function canPay(SubscriptionCycleInterface $cycle): bool
    {
        return $this->stateMachine->can($cycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_PAY);
    }

    public function pay(SubscriptionCycleInterface $cycle): void
    {
        $subscription = $cycle->getSubscription();
        Assert::notNull($subscription);

        // Counted before the transition, so what it publishes knows the deliveries left.
        $subscription->setPrepaidDeliveriesLeft($cycle->isCharging()
            ? $subscription->getDeliveriesPerCharge() - 1
            : max(0, $subscription->getPrepaidDeliveriesLeft() - 1));

        $this->stateMachine->apply($cycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_PAY);
        $cycle->setNextAttemptAt(null);

        foreach ($cycle->getItems() as $cycleItem) {
            $item = $cycleItem->getSubscriptionItem();
            if ($cycleItem->isIncluded() && null !== $item) {
                $item->setPaidCycles($item->getPaidCycles() + 1);
            }
        }

        $subscription->setConsecutiveFailedCycles(0);

        // A block paid late, by a retry or by its customer, is delivered from the cycle scheduled meanwhile.
        $openCycle = $this->scheduler->findOpenCycle($subscription);
        if (
            0 < $subscription->getPrepaidDeliveriesLeft() &&
            null !== $openCycle &&
            $openCycle->isCharging() &&
            null === $openCycle->getOrder()
        ) {
            $openCycle->setCharging(false);
        }

        $this->scheduler->scheduleNext($subscription);
    }
}
