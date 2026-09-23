<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Payment;

use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Webmozart\Assert\Assert;

/** What a real gateway does with its answer: an approval completes the payment, a decline fails it with the issuer's reason. */
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
            if ($this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE)) {
                $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
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
