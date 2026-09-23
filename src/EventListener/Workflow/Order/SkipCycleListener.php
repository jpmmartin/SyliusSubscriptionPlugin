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
 * An administrator who cancels a renewal order before it is charged skips that renewal: its cycle is
 * cancelled, not failed, so the run of failures stays as it was, and the next cycle is scheduled. The
 * plugin's own cancellations of a renewal order, when its cycle fails or is cancelled, find the cycle
 * already closed and change nothing.
 */
final class SkipCycleListener
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
        if (null === $cycle || !$this->stateMachine->can($cycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_CANCEL)) {
            return;
        }

        $this->stateMachine->apply($cycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_CANCEL);

        $subscription = $cycle->getSubscription();
        Assert::notNull($subscription);
        $this->scheduler->scheduleNext($subscription);
    }
}
