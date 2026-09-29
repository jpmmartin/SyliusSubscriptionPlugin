<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Installation;

use Sylius\Component\Core\Model\OrderItem;

/**
 * For a kernel test: the store behaves as one still on Sylius's own order item, which cannot carry
 * the plan, until restoreSubscriptions() or the test's end.
 */
trait WithholdsSubscriptions
{
    protected function withholdSubscriptions(): void
    {
        SwitchableOrderItemReadiness::withhold($this->orderItemReadinessSwitch(), OrderItem::class);
    }

    protected function restoreSubscriptions(): void
    {
        SwitchableOrderItemReadiness::restore($this->orderItemReadinessSwitch());
    }

    private function orderItemReadinessSwitch(): string
    {
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        \assert(\is_string($projectDir));

        return $projectDir . '/var/order_item_not_ready.txt';
    }
}
