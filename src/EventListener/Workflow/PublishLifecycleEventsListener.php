<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\EventListener\Workflow;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\EventPublisher;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalCancelled;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalFailed;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalHeld;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalOrderPlaced;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalPaid;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalRetried;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionActivated;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionCancelled;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionCompleted;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionEventInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionPaused;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionReactivated;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionResumed;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionSuspended;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionCycleTransitions;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Webmozart\Assert\Assert;

/**
 * Turns the transitions of the subscription and cycle graphs into the plugin's own events, so that a store
 * listens to names that stay when the graphs change. A transition without an event here publishes nothing.
 * Every datum is read from the entity as the transition completes: whatever an event carries is set before
 * its transition is applied. Publishing never stops a transition: one of a subscription, cycle or renewal
 * order not stored yet, without an identifier to carry, is logged and publishes nothing.
 */
final class PublishLifecycleEventsListener
{
    public function __construct(
        private readonly EventPublisher $publisher,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function onSubscriptionTransition(CompletedEvent $event): void
    {
        $subscription = $event->getSubject();
        Assert::isInstanceOf($subscription, SubscriptionInterface::class);
        $transition = (string) $event->getTransition()?->getName();
        $subscriptionId = $subscription->getId();
        if (null === $subscriptionId) {
            $this->notStored($transition, 'subscription');

            return;
        }

        $published = match ($transition) {
            SubscriptionTransitions::TRANSITION_ACTIVATE => new SubscriptionActivated($subscriptionId),
            SubscriptionTransitions::TRANSITION_PAUSE => new SubscriptionPaused($subscriptionId),
            SubscriptionTransitions::TRANSITION_RESUME => new SubscriptionResumed($subscriptionId),
            SubscriptionTransitions::TRANSITION_SUSPEND => new SubscriptionSuspended($subscriptionId, $subscription->isSuspendedForUnpaidRenewals()),
            SubscriptionTransitions::TRANSITION_REACTIVATE => new SubscriptionReactivated($subscriptionId),
            SubscriptionTransitions::TRANSITION_CANCEL => new SubscriptionCancelled($subscriptionId),
            SubscriptionTransitions::TRANSITION_COMPLETE => new SubscriptionCompleted($subscriptionId),
            default => null,
        };

        $this->publish($published);
    }

    public function onCycleTransition(CompletedEvent $event): void
    {
        $cycle = $event->getSubject();
        Assert::isInstanceOf($cycle, SubscriptionCycleInterface::class);
        $transition = (string) $event->getTransition()?->getName();
        $subscriptionId = $cycle->getSubscription()?->getId();
        $cycleId = $cycle->getId();
        if (null === $subscriptionId || null === $cycleId) {
            $this->notStored($transition, 'subscription cycle');

            return;
        }
        $number = $cycle->getNumber();
        $orderId = $cycle->getOrder()?->getId();
        $orderId = null === $orderId ? null : (int) $orderId;
        if (null === $orderId && \in_array($transition, [SubscriptionCycleTransitions::TRANSITION_PLACE_ORDER, SubscriptionCycleTransitions::TRANSITION_PAY], true)) {
            $this->notStored($transition, 'renewal order');

            return;
        }

        $published = match ($transition) {
            SubscriptionCycleTransitions::TRANSITION_HOLD => new RenewalHeld($subscriptionId, $cycleId, $number, $cycle->getHoldUntil(), $cycle->getHoldReason()),
            SubscriptionCycleTransitions::TRANSITION_PLACE_ORDER => new RenewalOrderPlaced($subscriptionId, $cycleId, $number, (int) $orderId),
            SubscriptionCycleTransitions::TRANSITION_PAY => new RenewalPaid($subscriptionId, $cycleId, $number, (int) $orderId),
            SubscriptionCycleTransitions::TRANSITION_FAIL => new RenewalFailed($subscriptionId, $cycleId, $number, $orderId, $cycle->getCancellationReason()),
            SubscriptionCycleTransitions::TRANSITION_RETRY => new RenewalRetried($subscriptionId, $cycleId, $number),
            // A skipped renewal is announced by the skipper, once the next date is known.
            SubscriptionCycleTransitions::TRANSITION_CANCEL => $cycle->isSkipped() ? null : new RenewalCancelled($subscriptionId, $cycleId, $number, $orderId, $cycle->getCancellationReason()),
            default => null,
        };

        $this->publish($published);
    }

    private function publish(?SubscriptionEventInterface $event): void
    {
        if (null !== $event) {
            $this->publisher->publish($event);
        }
    }

    private function notStored(string $transition, string $what): void
    {
        $this->logger->warning('No event published for the "{transition}" transition: the {what} is not stored yet.', [
            'transition' => $transition,
            'what' => $what,
        ]);
    }
}
