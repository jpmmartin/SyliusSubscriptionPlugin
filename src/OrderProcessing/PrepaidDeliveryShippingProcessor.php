<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\OrderProcessing;

use JpmMartin\SyliusSubscriptionPlugin\Order\PrepaidDeliveryOrders;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Order\Model\OrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;

/**
 * A prepaid delivery ships for nothing: the charge of its block paid for it. Runs right after Sylius's
 * shipping charges (priority 30) and before promotions and taxes, so nothing is worked out on them.
 */
final class PrepaidDeliveryShippingProcessor implements OrderProcessorInterface
{
    public function __construct(private readonly PrepaidDeliveryOrders $prepaidDeliveryOrders)
    {
    }

    public function process(OrderInterface $order): void
    {
        if ($this->prepaidDeliveryOrders->has($order)) {
            $order->removeAdjustmentsRecursively(AdjustmentInterface::SHIPPING_ADJUSTMENT);
        }
    }
}
