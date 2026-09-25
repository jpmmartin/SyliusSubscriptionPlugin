<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\EventListener\Workflow\Order;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionRecoveryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Payment\PluginCharges;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionCycleRepositoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Webmozart\Assert\Assert;

/**
 * A customer's recovery paid: the subscription suspended after failed cycles is reactivated, with its
 * calendar and its run of failures started afresh. It runs before PayCycleListener, while the cycle
 * still says it is a recovery: paying it ends the retry. An administrator's retry, which the plugin
 * charges, leaves the subscription as it was: lifting a suspension stays theirs to decide.
 */
final class ReactivateRecoveredSubscriptionListener
{
    /** @param SubscriptionCycleRepositoryInterface<SubscriptionCycleInterface> $cycleRepository */
    public function __construct(
        private readonly SubscriptionCycleRepositoryInterface $cycleRepository,
        private readonly SubscriptionRecoveryInterface $recovery,
        private readonly StateMachineInterface $stateMachine,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $order = $event->getSubject();
        Assert::isInstanceOf($order, OrderInterface::class);

        $cycle = $this->cycleRepository->findOneByOrder($order);
        $subscription = $cycle?->getSubscription();
        $payment = $order->getLastPayment(PaymentInterface::STATE_COMPLETED);
        if (
            null === $cycle ||
            null === $subscription ||
            null === $payment ||
            !$this->recovery->isAwaitingItsCustomer($cycle) ||
            SubscriptionInterface::STATE_SUSPENDED !== $subscription->getState() ||
            !$subscription->isSuspendedForFailedCycles() ||
            PluginCharges::include($cycle, $payment) ||
            !$this->stateMachine->can($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_REACTIVATE)
        ) {
            return;
        }

        $this->stateMachine->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_REACTIVATE);
    }
}
