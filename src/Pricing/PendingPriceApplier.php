<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Pricing;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\EventPublisher;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionPriceChanged;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Psr\Clock\ClockInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Webmozart\Assert\Assert;

/**
 * The announced increases a renewal brings into force, applied where its order is about to be placed:
 * each item whose increase applies from the cycle's date or before takes its new price. When the
 * policy asks for the customer's acceptance and it was not given, the subscription is paused instead,
 * which cancels the cycle; resuming it then asks for the acceptance.
 */
final class PendingPriceApplier
{
    public function __construct(
        private readonly PriceIncreaseAcceptancePolicyInterface $acceptancePolicy,
        private readonly StateMachineInterface $stateMachine,
        private readonly ClockInterface $clock,
        private readonly EventPublisher $eventPublisher,
    ) {
    }

    /** @return bool false when the subscription was paused for an increase it did not accept, and the cycle is not to renew */
    public function applyDue(SubscriptionCycleInterface $cycle): bool
    {
        $subscription = $cycle->getSubscription();
        $scheduledAt = $cycle->getScheduledAt();
        Assert::notNull($subscription);
        Assert::notNull($scheduledAt);

        // A prepaid delivery was paid for at its block's price: an increase waits for the next charge.
        if (!$cycle->isCharging()) {
            return true;
        }

        $due = [];
        foreach ($subscription->getItems() as $item) {
            $from = $item->getPendingPriceFrom();
            if (!$item->isRemoved() && $item->hasPendingPrice() && null !== $from && $from <= $scheduledAt) {
                $due[] = $item;
            }
        }
        if ([] === $due) {
            return true;
        }

        if ($this->awaitsAcceptance($subscription)) {
            if ($this->stateMachine->can($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_PAUSE)) {
                $this->stateMachine->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_PAUSE);
            }

            return false;
        }

        $totalBefore = $subscription->getRenewalTotal();
        foreach ($due as $item) {
            $item->setUnitPrice((int) $item->getPendingUnitPrice());
            $item->setPendingPrice(null, null);
        }

        $subscriptionId = $subscription->getId();
        if (null !== $subscriptionId) {
            $this->eventPublisher->publish(new SubscriptionPriceChanged($subscriptionId, $totalBefore, $subscription->getRenewalTotal()));
        }

        return true;
    }

    /** Whether an increase is pending that the policy asks the customer to accept, and they have not. */
    public function awaitsAcceptance(SubscriptionInterface $subscription): bool
    {
        return $this->hasPendingIncrease($subscription) &&
            null === $subscription->getPriceIncreaseAcceptedAt() &&
            $this->acceptancePolicy->requiresAcceptance($subscription);
    }

    /** The customer accepts every increase pending on the subscription, now. */
    public function accept(SubscriptionInterface $subscription): void
    {
        Assert::true($this->hasPendingIncrease($subscription), 'No price increase is pending on this subscription.');

        $subscription->setPriceIncreaseAcceptedAt($this->clock->now());
    }

    public function hasPendingIncrease(SubscriptionInterface $subscription): bool
    {
        return $subscription->getItems()->exists(
            static fn (int|string $key, $item): bool => !$item->isRemoved() && $item->hasPendingPrice(),
        );
    }
}
