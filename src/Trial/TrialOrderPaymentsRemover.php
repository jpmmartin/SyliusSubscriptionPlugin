<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Trial;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Payment\Remover\OrderPaymentsRemoverInterface;

/** Sylius drops the payments of an order of 0; a free trial's order keeps its payment of 0. */
final class TrialOrderPaymentsRemover implements OrderPaymentsRemoverInterface
{
    public function __construct(
        private readonly OrderPaymentsRemoverInterface $decorated,
        private readonly TrialOrders $trialOrders,
    ) {
    }

    public function canRemovePayments(OrderInterface $order): bool
    {
        return !$this->trialOrders->keepsAZeroPayment($order) && $this->decorated->canRemovePayments($order);
    }

    public function removePayments(OrderInterface $order): void
    {
        $this->decorated->removePayments($order);
    }
}
