<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Gate;

use JpmMartin\SyliusSubscriptionPlugin\Cycle\CycleFailureHandlerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionCycleTransitions;
use Psr\Clock\ClockInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;

/**
 * A rejection from any gate outweighs the others. The deadline of a hold is the earliest one asked for
 * and is set once, when the cycle is first held, so a gate that keeps asking to wait cannot hold a
 * cycle forever; the reason shown is always the latest.
 */
final class CycleGateKeeper implements CycleGateKeeperInterface
{
    /** @param iterable<CycleGateInterface> $gates */
    public function __construct(
        private readonly iterable $gates,
        private readonly StateMachineInterface $stateMachine,
        private readonly CycleFailureHandlerInterface $failureHandler,
        private readonly ClockInterface $clock,
    ) {
    }

    public function admit(SubscriptionCycleInterface $cycle): bool
    {
        $until = null;
        $reasons = [];
        foreach ($this->gates as $gate) {
            $decision = $gate->check($cycle);

            if ($decision->rejects()) {
                $this->failureHandler->fail($cycle, (string) $decision->reason);

                return false;
            }

            if ($decision->waits()) {
                $until = null === $until || $decision->until < $until ? $decision->until : $until;
                $reasons[] = (string) $decision->reason;
            }
        }

        if (null === $until) {
            return true;
        }

        if ($this->stateMachine->can($cycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_HOLD)) {
            $this->stateMachine->apply($cycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_HOLD);
            $cycle->setHoldUntil($until);
        }
        $cycle->setHoldReason(implode(' ', $reasons));

        $holdUntil = $cycle->getHoldUntil();
        if (null !== $holdUntil && $this->clock->now() >= $holdUntil) {
            $this->failureHandler->fail($cycle, (string) $cycle->getHoldReason());
        }

        return false;
    }
}
