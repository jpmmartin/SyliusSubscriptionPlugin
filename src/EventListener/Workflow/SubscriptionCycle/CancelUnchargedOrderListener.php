<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\EventListener\Workflow\SubscriptionCycle;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Order\OrderTransitions;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Webmozart\Assert\Assert;

/**
 * A cancelled cycle takes its order with it while nothing has been charged on it, which gives the
 * reserved stock back. A charged order is never touched.
 */
final class CancelUnchargedOrderListener
{
    private const UNCHARGED_PAYMENT_STATES = [OrderPaymentStates::STATE_CART, OrderPaymentStates::STATE_AWAITING_PAYMENT];

    public function __construct(private readonly StateMachineInterface $stateMachine)
    {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $cycle = $event->getSubject();
        Assert::isInstanceOf($cycle, SubscriptionCycleInterface::class);

        $order = $cycle->getOrder();
        if (
            null !== $order &&
            \in_array($order->getPaymentState(), self::UNCHARGED_PAYMENT_STATES, true) &&
            $this->stateMachine->can($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL)
        ) {
            $this->stateMachine->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);
        }
    }
}
