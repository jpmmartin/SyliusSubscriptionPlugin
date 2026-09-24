<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Query;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\MissedCyclePolicyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionCalendarInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionSchedulerInterface;
use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

final class CommittedCyclesQuery implements CommittedCyclesQueryInterface
{
    /** @param SubscriptionRepositoryInterface<SubscriptionInterface> $subscriptionRepository */
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly SubscriptionSchedulerInterface $scheduler,
        private readonly SubscriptionCalendarInterface $calendar,
        private readonly ClockInterface $clock,
        private readonly MissedCyclePolicyInterface $missedCyclePolicy,
    ) {
    }

    public function forProductVariant(ProductVariantInterface $productVariant, \DateInterval $horizon): array
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $until = $now->add($horizon);

        $committed = [];
        foreach ($this->subscriptionRepository->findActiveByProductVariant($productVariant) as $subscription) {
            $openCycle = $this->scheduler->findOpenCycle($subscription);
            $scheduledAt = $openCycle?->getScheduledAt();
            if (null === $openCycle || null === $scheduledAt) {
                continue;
            }

            // As the cycles will go: the open one unless the missed cycle policy cancels it, then the
            // dates after it that the policy does not skip. A skipped date only shifts the calendar.
            $openIsCharged = SubscriptionCycleInterface::STATE_SCHEDULED !== $openCycle->getState() ||
                $scheduledAt > $now ||
                $this->missedCyclePolicy->isStillDue($openCycle, $now);
            $shift = $this->missedCyclePolicy->datesToSkip($subscription, $openCycle->getNumber() + 1, $now);

            foreach ($subscription->getItems() as $item) {
                if ($item->getProductVariant()?->getId() !== $productVariant->getId()) {
                    continue;
                }

                $maxCycles = $item->getTerms()?->getMaxCycles();
                $remaining = null === $maxCycles ? \PHP_INT_MAX : $maxCycles - $item->getPaidCycles();
                $number = $openCycle->getNumber();
                if ($openIsCharged && 0 < $remaining && $scheduledAt <= $until) {
                    $committed[] = new CommittedCycle($subscription, $item, $number, $scheduledAt, $item->getQuantity());
                    --$remaining;
                }
                ++$number;
                $date = $this->calendar->dateOfCycle($subscription, $number + $shift);
                while (0 < $remaining && $date <= $until) {
                    $committed[] = new CommittedCycle($subscription, $item, $number, $date, $item->getQuantity());
                    --$remaining;
                    ++$number;
                    $date = $this->calendar->dateOfCycle($subscription, $number + $shift);
                }
            }
        }

        usort(
            $committed,
            static fn (CommittedCycle $a, CommittedCycle $b): int => [$a->date, $a->subscription->getId(), $a->number, $a->item->getId()] <=> [$b->date, $b->subscription->getId(), $b->number, $b->item->getId()],
        );

        return $committed;
    }
}
