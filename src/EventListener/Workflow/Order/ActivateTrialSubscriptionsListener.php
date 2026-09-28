<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\EventListener\Workflow\Order;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use JpmMartin\SyliusSubscriptionPlugin\Trial\TrialOrders;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Webmozart\Assert\Assert;

/**
 * An initial order whose only charge is a free trial's has nothing to pay: its payment of 0 authorized,
 * so the gateway keeps the card, activates the subscriptions it started. Any other order is only
 * activated once paid, whatever its payments' authorization.
 */
final class ActivateTrialSubscriptionsListener
{
    /** @param SubscriptionRepositoryInterface<SubscriptionInterface> $subscriptionRepository */
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly StateMachineInterface $stateMachine,
        private readonly TrialOrders $trialOrders,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $order = $event->getSubject();
        Assert::isInstanceOf($order, OrderInterface::class);

        if (!$this->trialOrders->keepsAZeroPayment($order)) {
            return;
        }

        foreach ($this->subscriptionRepository->findByInitialOrder($order) as $subscription) {
            if ($this->stateMachine->can($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_ACTIVATE)) {
                $this->stateMachine->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_ACTIVATE);
            }
        }
    }
}
