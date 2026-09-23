<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Schedule;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;

interface SubscriptionCalendarInterface
{
    /**
     * The date cycle $number falls on: the schedule's anchor plus the billing interval once per cycle
     * after the anchor's, so a late charge never moves the cycles after it. The anchor is the
     * activation, or the open cycle when the plan was changed.
     */
    public function dateOfCycle(SubscriptionInterface $subscription, int $number): \DateTimeImmutable;
}
