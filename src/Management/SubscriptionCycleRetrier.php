<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Management;

use JpmMartin\SyliusSubscriptionPlugin\CommandHandler\ProcessSubscriptionCycleHandler;
use JpmMartin\SyliusSubscriptionPlugin\Cycle\CycleChargerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Cycle\CycleFailureHandlerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Order\RenewalOrderPlacerInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionCycleTransitions;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Webmozart\Assert\Assert;

/**
 * The cycle is marked as a manual retry while its charge is settled, so the scheduler neither charges
 * it again nor counts it as the open cycle. Its earlier order stays linked from its attempts; the cycle
 * points at the new one. No gate is asked: the administrator decided.
 */
final class SubscriptionCycleRetrier implements SubscriptionCycleRetrierInterface
{
    private const RETRIABLE_SUBSCRIPTION_STATES = [SubscriptionInterface::STATE_ACTIVE, SubscriptionInterface::STATE_PAUSED, SubscriptionInterface::STATE_SUSPENDED];

    public function __construct(
        private readonly StateMachineInterface $stateMachine,
        private readonly RenewalOrderPlacerInterface $renewalOrderPlacer,
        private readonly CycleChargerInterface $cycleCharger,
        private readonly CycleFailureHandlerInterface $failureHandler,
    ) {
    }

    public function canRetry(SubscriptionCycleInterface $cycle): bool
    {
        return \in_array($cycle->getSubscription()?->getState(), self::RETRIABLE_SUBSCRIPTION_STATES, true) &&
            $this->stateMachine->can($cycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_RETRY);
    }

    public function retry(SubscriptionCycleInterface $cycle): void
    {
        Assert::true($this->canRetry($cycle), 'Only a failed cycle of an active, paused or suspended subscription can be retried.');

        $this->stateMachine->apply($cycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_RETRY);
        $cycle->setManualRetry(true);
        $cycle->setCancellationReason(null);

        if (null === $this->renewalOrderPlacer->place($cycle)) {
            $this->failureHandler->fail($cycle, ProcessSubscriptionCycleHandler::NOTHING_TO_RENEW);

            return;
        }

        $this->cycleCharger->charge($cycle);
    }
}
