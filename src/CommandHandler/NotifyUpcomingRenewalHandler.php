<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\CommandHandler;

use JpmMartin\SyliusSubscriptionPlugin\Command\NotifyUpcomingRenewal;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\EventPublisher;
use JpmMartin\SyliusSubscriptionPlugin\Event\IntroductoryPriceEnding;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalUpcoming;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionCycleRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * Announces a renewal once: the cycle keeps when it was announced, stored with its version checked, and the
 * event is delivered once that is stored. A cycle that changed since it was read, or that no longer should
 * be announced, is left alone. When the renewal is the first in which an item leaves its introductory
 * price, that is announced too, with what the renewal will charge.
 */
final class NotifyUpcomingRenewalHandler
{
    /** @param SubscriptionCycleRepositoryInterface<SubscriptionCycleInterface> $cycleRepository */
    public function __construct(
        private readonly SubscriptionCycleRepositoryInterface $cycleRepository,
        private readonly EventPublisher $eventPublisher,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(NotifyUpcomingRenewal $command): void
    {
        $cycle = $this->cycleRepository->findUnchangedSince($command->cycleId, $command->version);
        $subscription = $cycle?->getSubscription();
        $scheduledAt = $cycle?->getScheduledAt();
        $now = $this->clock->now();
        if (
            null === $cycle ||
            null === $subscription ||
            null === $scheduledAt ||
            SubscriptionInterface::STATE_ACTIVE !== $subscription->getState() ||
            SubscriptionCycleInterface::STATE_SCHEDULED !== $cycle->getState() ||
            $scheduledAt <= $now ||
            null !== $cycle->getRenewalNoticeAt()
        ) {
            return;
        }

        $cycle->setRenewalNoticeAt($now);

        $subscriptionId = $subscription->getId();
        $cycleId = $cycle->getId();
        if (null === $subscriptionId || null === $cycleId) {
            return;
        }
        $this->eventPublisher->publish(new RenewalUpcoming($subscriptionId, $cycleId, $cycle->getNumber(), $scheduledAt));

        if (self::endsAnIntroductoryPrice($subscription)) {
            $this->eventPublisher->publish(new IntroductoryPriceEnding($subscriptionId, $cycleId, $cycle->getNumber(), $scheduledAt, self::renewalTotal($subscription, $scheduledAt)));
        }
    }

    /**
     * Whether an item still renewing has paid exactly its introductory cycles, which an item without an
     * introductory price has none of: the next is its first at its normal price.
     */
    private static function endsAnIntroductoryPrice(SubscriptionInterface $subscription): bool
    {
        foreach ($subscription->getItems() as $item) {
            if ($item->isRenewable() && $item->getPaidCycles() === $item->getIntroductoryCycles()) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the renewal charges for its items: each at its introductory price if it is still on it, else at
     * an increase that applies by then, else at its frozen price.
     */
    private static function renewalTotal(SubscriptionInterface $subscription, \DateTimeImmutable $scheduledAt): int
    {
        $total = 0;
        foreach ($subscription->getItems() as $item) {
            if (!$item->isRenewable()) {
                continue;
            }

            $unitPrice = $item->getUnitPriceForCycle();
            $increaseFrom = $item->getPendingPriceFrom();
            if (!$item->isOnIntroductoryPrice() && null !== $increaseFrom && $increaseFrom <= $scheduledAt) {
                $unitPrice = (int) $item->getPendingUnitPrice();
            }
            $total += $unitPrice * $item->getQuantity();
        }

        return $total;
    }
}
