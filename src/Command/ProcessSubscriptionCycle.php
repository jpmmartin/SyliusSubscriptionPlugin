<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Command;

/** One due cycle, as it was when it was found due: handled only if it has not changed since. */
final class ProcessSubscriptionCycle
{
    public function __construct(
        public readonly int $cycleId,
        public readonly int $version,
    ) {
    }
}
