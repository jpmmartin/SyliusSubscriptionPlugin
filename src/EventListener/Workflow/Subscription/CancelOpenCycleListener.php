<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\EventListener\Workflow\Subscription;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionCycleTransitions;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Webmozart\Assert\Assert;

/**
 * A cancelled or suspended subscription has no open cycle left: a suspended one generates no cycles
 * until it is reactivated. Cancelling the cycle also cancels its order while nothing has been charged
 * on it; paid cycles and their orders are left alone.
 *
 * An administrator's retry still awaiting the gateway's answer survives a suspension, since the
 * scheduler keeps reconciling the retries of suspended subscriptions; a cancellation takes it too,
 * since nothing follows the cycles of a cancelled subscription any more.
 */
final class CancelOpenCycleListener
{
    public function __construct(private readonly StateMachineInterface $stateMachine)
    {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $subscription = $event->getSubject();
        Assert::isInstanceOf($subscription, SubscriptionInterface::class);

        $suspending = SubscriptionTransitions::TRANSITION_SUSPEND === $event->getTransition()?->getName();
        foreach ($subscription->getCycles() as $cycle) {
            if ($suspending && $cycle->isManualRetry()) {
                continue;
            }

            if ($this->stateMachine->can($cycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_CANCEL)) {
                $this->stateMachine->apply($cycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_CANCEL);
            }
        }
    }
}
