<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Installation;

use JpmMartin\SyliusSubscriptionPlugin\Installation\OrderItemReadinessInterface;

/**
 * The test application's order item carries the plan, so it is always ready, unless a test says the
 * store has not made the step yet: it writes a file of the test application, as the price increase
 * switch does, so the requests of a test and the test itself agree.
 */
final class SwitchableOrderItemReadiness implements OrderItemReadinessInterface
{
    public function __construct(
        private readonly OrderItemReadinessInterface $readiness,
        private readonly string $switchFile,
    ) {
    }

    public function isReady(): bool
    {
        return !is_file($this->switchFile) && $this->readiness->isReady();
    }

    public function orderItemClass(): string
    {
        return is_file($this->switchFile) ? trim((string) file_get_contents($this->switchFile)) : $this->readiness->orderItemClass();
    }

    /** As a store still on Sylius's own order item would be. */
    public static function withhold(string $switchFile, string $orderItemClass): void
    {
        file_put_contents($switchFile, $orderItemClass);
    }

    public static function restore(string $switchFile): void
    {
        if (is_file($switchFile)) {
            unlink($switchFile);
        }
    }
}
