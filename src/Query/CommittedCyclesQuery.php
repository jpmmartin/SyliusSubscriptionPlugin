<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Query;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
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
    ) {
    }

    public function forProductVariant(ProductVariantInterface $productVariant, \DateInterval $horizon): array
    {
        $until = \DateTimeImmutable::createFromInterface($this->clock->now())->add($horizon);

        $committed = [];
        foreach ($this->subscriptionRepository->findActiveByProductVariant($productVariant) as $subscription) {
            $openCycle = $this->scheduler->findOpenCycle($subscription);
            $scheduledAt = $openCycle?->getScheduledAt();
            if (null === $openCycle || null === $scheduledAt) {
                continue;
            }

            foreach ($subscription->getItems() as $item) {
                if ($item->getProductVariant()?->getId() !== $productVariant->getId()) {
                    continue;
                }

                $maxCycles = $item->getTerms()?->getMaxCycles();
                $remaining = null === $maxCycles ? \PHP_INT_MAX : $maxCycles - $item->getPaidCycles();
                $number = $openCycle->getNumber();
                $date = $scheduledAt;
                while (0 < $remaining && $date <= $until) {
                    $committed[] = new CommittedCycle($subscription, $item, $number, $date, $item->getQuantity());
                    --$remaining;
                    ++$number;
                    $date = $this->calendar->dateOfCycle($subscription, $number);
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
