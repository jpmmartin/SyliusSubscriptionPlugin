<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\EventListener\Workflow\Order;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Webmozart\Assert\Assert;

/** A paid initial order activates the subscriptions it started, on the day it is paid. */
final class ActivateSubscriptionsListener
{
    /** @param SubscriptionRepositoryInterface<SubscriptionInterface> $subscriptionRepository */
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly StateMachineInterface $stateMachine,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $order = $event->getSubject();
        Assert::isInstanceOf($order, OrderInterface::class);

        foreach ($this->subscriptionRepository->findByInitialOrder($order) as $subscription) {
            if ($this->stateMachine->can($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_ACTIVATE)) {
                $this->stateMachine->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_ACTIVATE);
            }
        }
    }
}
