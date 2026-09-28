<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Payment;

use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Webmozart\Assert\Assert;

/**
 * What a real gateway does with its answer: an approval completes the payment, or authorizes it when
 * the request only asks for an authorization, and a decline fails it with the issuer's reason.
 */
final class ScriptedPaymentRequestHandler
{
    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly StateMachineInterface $stateMachine,
        private readonly ScriptedGateway $gateway,
    ) {
    }

    public function __invoke(ScriptedPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        $payment = $paymentRequest->getPayment();
        Assert::notNull($payment);

        [$answer, $reason, $code] = $this->gateway->answer((string) $paymentRequest->getAction());

        if (ScriptedGateway::APPROVE === $answer) {
            $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);
            // A status request reports what happened: an authorized payment stays authorized.
            $transition = match ($paymentRequest->getAction()) {
                PaymentRequestInterface::ACTION_AUTHORIZE => PaymentTransitions::TRANSITION_AUTHORIZE,
                PaymentRequestInterface::ACTION_STATUS => PaymentInterface::STATE_AUTHORIZED === $payment->getState() ? null : PaymentTransitions::TRANSITION_COMPLETE,
                default => PaymentTransitions::TRANSITION_COMPLETE,
            };
            if (null !== $transition && $this->stateMachine->can($payment, PaymentTransitions::GRAPH, $transition)) {
                $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);
            }

            return;
        }

        if (ScriptedGateway::DECLINE === $answer) {
            $paymentRequest->setResponseData(array_filter(['reason' => $reason ?? 'Declined.', 'code' => $code], static fn (?string $value): bool => null !== $value));
            $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL);
            if ($this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_FAIL)) {
                $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_FAIL);
            }
        }
    }
}
