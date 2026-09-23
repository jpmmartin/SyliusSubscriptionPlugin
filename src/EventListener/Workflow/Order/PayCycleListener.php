<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\EventListener\Workflow\Order;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionCycleRepositoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionSchedulerInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionCycleTransitions;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Webmozart\Assert\Assert;

/**
 * A paid renewal order pays its cycle, however it came to be paid: by the renewal charge, by a later
 * status check, by an administrator's retry or by hand in the admin. Each item it carried counts one
 * more paid cycle, the run of failures ends and the schedule goes on, unless a cycle is already open.
 */
final class PayCycleListener
{
    /** @param SubscriptionCycleRepositoryInterface<SubscriptionCycleInterface> $cycleRepository */
    public function __construct(
        private readonly SubscriptionCycleRepositoryInterface $cycleRepository,
        private readonly StateMachineInterface $stateMachine,
        private readonly SubscriptionSchedulerInterface $scheduler,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $order = $event->getSubject();
        Assert::isInstanceOf($order, OrderInterface::class);

        $cycle = $this->cycleRepository->findOneByOrder($order);
        if (null === $cycle || !$this->stateMachine->can($cycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_PAY)) {
            return;
        }

        $this->stateMachine->apply($cycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_PAY);
        $cycle->setNextAttemptAt(null);

        foreach ($cycle->getItems() as $cycleItem) {
            $item = $cycleItem->getSubscriptionItem();
            if ($cycleItem->isIncluded() && null !== $item) {
                $item->setPaidCycles($item->getPaidCycles() + 1);
            }
        }

        $subscription = $cycle->getSubscription();
        Assert::notNull($subscription);
        $subscription->setConsecutiveFailedCycles(0);
        $this->scheduler->scheduleNext($subscription);
    }
}
