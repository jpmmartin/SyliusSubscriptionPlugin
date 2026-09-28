<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Trial;

use Sylius\Component\Core\Checker\OrderPaymentMethodSelectionRequirementCheckerInterface;
use Sylius\Component\Core\Model\OrderInterface;

/** Sylius skips the payment step of an order of 0; a free trial's order goes through it, to keep the card. */
final class TrialPaymentMethodSelectionRequirementChecker implements OrderPaymentMethodSelectionRequirementCheckerInterface
{
    public function __construct(
        private readonly OrderPaymentMethodSelectionRequirementCheckerInterface $decorated,
        private readonly TrialOrders $trialOrders,
    ) {
    }

    public function isPaymentMethodSelectionRequired(OrderInterface $order): bool
    {
        return $this->trialOrders->keepsAZeroPayment($order) || $this->decorated->isPaymentMethodSelectionRequired($order);
    }
}
