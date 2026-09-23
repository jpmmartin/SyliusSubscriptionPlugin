<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Schedule;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionCycleTransitions;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Webmozart\Assert\Assert;

/**
 * The next cycle's number follows the last one, whatever became of it, and its date comes from the
 * calendar, so a failed or late cycle never moves the ones after it.
 */
final class SubscriptionScheduler implements SubscriptionSchedulerInterface
{
    private const OPEN_STATES = [
        SubscriptionCycleInterface::STATE_SCHEDULED,
        SubscriptionCycleInterface::STATE_ON_HOLD,
        SubscriptionCycleInterface::STATE_AWAITING_PAYMENT,
    ];

    /** @param FactoryInterface<SubscriptionCycleInterface> $cycleFactory */
    public function __construct(
        private readonly FactoryInterface $cycleFactory,
        private readonly SubscriptionCalendarInterface $calendar,
        private readonly StateMachineInterface $stateMachine,
    ) {
    }

    public function scheduleNext(SubscriptionInterface $subscription): void
    {
        // A subscription that stopped meanwhile schedules nothing more.
        if (SubscriptionInterface::STATE_ACTIVE !== $subscription->getState()) {
            return;
        }

        $openCycle = $this->findOpenCycle($subscription);

        // The maximum counts each item's paid cycles: one that failed or skipped it does not use up its plan.
        $renewable = $subscription->getItems()->exists(
            static fn (int|string $key, SubscriptionItemInterface $item): bool => $item->isRenewable(),
        );
        if (!$renewable) {
            // A manual retry paid late can use up the last item after the next cycle was scheduled.
            if (null !== $openCycle && $this->stateMachine->can($openCycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_CANCEL)) {
                $this->stateMachine->apply($openCycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_CANCEL);
            }
            $this->stateMachine->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_COMPLETE);

            return;
        }

        if (null !== $openCycle) {
            return;
        }

        $number = 1;
        foreach ($subscription->getCycles() as $cycle) {
            $number = max($number, $cycle->getNumber() + 1);
        }

        $next = $this->cycleFactory->createNew();
        Assert::isInstanceOf($next, SubscriptionCycleInterface::class);
        $next->setNumber($number);
        $next->setScheduledAt($this->calendar->dateOfCycle($subscription, $number));
        $subscription->addCycle($next);
    }

    public function findOpenCycle(SubscriptionInterface $subscription): ?SubscriptionCycleInterface
    {
        foreach ($subscription->getCycles() as $cycle) {
            if (!$cycle->isManualRetry() && \in_array($cycle->getState(), self::OPEN_STATES, true)) {
                return $cycle;
            }
        }

        return null;
    }
}
