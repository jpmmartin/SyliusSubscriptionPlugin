<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\CommandHandler;

use JpmMartin\SyliusSubscriptionPlugin\Command\NotifyUpcomingRenewal;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\EventPublisher;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalUpcoming;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionCycleRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * Announces a renewal once: the cycle keeps when it was announced, stored with its version checked, and the
 * event is delivered once that is stored. A cycle that changed since it was read, or that no longer should
 * be announced, is left alone.
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
    }
}
