<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Command;

/** Announce the renewal of the cycle read at $version, unless it changed since. */
final class NotifyUpcomingRenewal
{
    public function __construct(
        public readonly int $cycleId,
        public readonly int $version,
    ) {
    }
}
