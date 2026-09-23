<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Order;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use Sylius\Component\Core\Model\OrderInterface;

interface RenewalOrderPlacerInterface
{
    /**
     * Records on the cycle, in place of what an earlier order of it recorded, each item still renewing:
     * taken into the order, or skipped because its variant cannot be sold now. Then places the
     * completed order of the items taken: the subscription's customer and channel, a line per item at
     * its frozen price, the addresses of its last order and its methods. Taxes, shipping charges and
     * automatic promotions are worked out as for any order.
     *
     * Returns null, placing no order, when every item was skipped. The cycle's own state is left to the
     * caller.
     */
    public function place(SubscriptionCycleInterface $cycle): ?OrderInterface;
}
