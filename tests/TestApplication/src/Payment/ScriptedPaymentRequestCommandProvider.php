<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Payment;

use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

final class ScriptedPaymentRequestCommandProvider implements PaymentRequestCommandProviderInterface
{
    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return \in_array($paymentRequest->getAction(), [PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::ACTION_STATUS], true);
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        return new ScriptedPaymentRequest($paymentRequest->getId());
    }
}
