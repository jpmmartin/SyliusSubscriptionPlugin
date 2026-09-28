<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Trial;

use Sylius\Bundle\PaymentBundle\Provider\DefaultActionProviderInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * The payment of 0 of a free trial's order is authorized, whatever its method does with other orders:
 * authorizing is how the gateway keeps the card without charging it.
 */
final class TrialPaymentActionProvider implements DefaultActionProviderInterface
{
    public function __construct(
        private readonly DefaultActionProviderInterface $decorated,
        private readonly TrialOrders $trialOrders,
    ) {
    }

    public function getAction(PaymentRequestInterface $paymentRequest): string
    {
        $payment = $paymentRequest->getPayment();
        if ($payment instanceof PaymentInterface && 0 === $payment->getAmount()) {
            $order = $payment->getOrder();
            if ($order instanceof OrderInterface && $this->trialOrders->keepsAZeroPayment($order)) {
                return PaymentRequestInterface::ACTION_AUTHORIZE;
            }
        }

        return $this->decorated->getAction($paymentRequest);
    }

    public function getActionFromPaymentMethodCode(string $paymentMethodCode, ?string $defaultAction = null): string
    {
        return $this->decorated->getActionFromPaymentMethodCode($paymentMethodCode, $defaultAction);
    }

    public function getActionFromPaymentMethod(PaymentMethodInterface $paymentMethod, ?string $defaultAction = null): string
    {
        return $this->decorated->getActionFromPaymentMethod($paymentMethod, $defaultAction);
    }
}
