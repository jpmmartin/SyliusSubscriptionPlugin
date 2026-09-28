<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Management;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionCalendarInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionSchedulerInterface;

/** The deliveries a subscription has paid for and not received yet, and when the last of them comes. */
final class PrepaidDeliveries
{
    public function __construct(
        private readonly SubscriptionSchedulerInterface $scheduler,
        private readonly SubscriptionCalendarInterface $calendar,
    ) {
    }

    /** The date of its last delivery paid for, when some are still to come and one is scheduled. */
    public function lastDeliveryAt(SubscriptionInterface $subscription): ?\DateTimeImmutable
    {
        $left = $subscription->getPrepaidDeliveriesLeft();
        $openCycle = $this->scheduler->findOpenCycle($subscription);
        if (0 === $left || null === $openCycle || $openCycle->isCharging()) {
            return null;
        }

        return $this->calendar->dateOfCycle($subscription, $openCycle->getNumber() + $left - 1);
    }
}
