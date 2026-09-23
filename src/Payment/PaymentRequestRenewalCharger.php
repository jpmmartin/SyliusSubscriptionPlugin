<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Payment;

use Sylius\Bundle\PaymentBundle\Announcer\PaymentRequestAnnouncerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Factory\PaymentRequestFactoryInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

/**
 * The renewal charger a store gets out of the box: a capture through Sylius's payment requests,
 * handled by whichever gateway the payment's method uses.
 *
 * Only the methods the plugin's configuration names are charged; any other is refused before a
 * gateway is contacted, and checkout does not let a subscription be taken with it. The outcome is
 * read from the payment once the request has been handled: completed is approved, failed or
 * cancelled is declined, and anything else, including a request handled asynchronously, is
 * unknown, to be settled later with status().
 */
final class PaymentRequestRenewalCharger implements RenewalChargerInterface
{
    /**
     * @param PaymentRequestFactoryInterface<PaymentRequestInterface> $paymentRequestFactory
     * @param RepositoryInterface<PaymentRequestInterface> $paymentRequestRepository
     * @param list<string> $paymentMethodCodes
     */
    public function __construct(
        private readonly PaymentRequestFactoryInterface $paymentRequestFactory,
        private readonly RepositoryInterface $paymentRequestRepository,
        private readonly PaymentRequestAnnouncerInterface $paymentRequestAnnouncer,
        private readonly array $paymentMethodCodes,
    ) {
    }

    public function supports(PaymentMethodInterface $paymentMethod): bool
    {
        return \in_array($paymentMethod->getCode(), $this->paymentMethodCodes, true);
    }

    public function charge(PaymentInterface $payment): ChargeOutcome
    {
        return $this->request($payment, PaymentRequestInterface::ACTION_CAPTURE);
    }

    public function status(PaymentInterface $payment): ChargeOutcome
    {
        return $this->request($payment, PaymentRequestInterface::ACTION_STATUS);
    }

    private function request(PaymentInterface $payment, string $action): ChargeOutcome
    {
        $method = $payment->getMethod();
        if (!$method instanceof PaymentMethodInterface || !$this->supports($method)) {
            return ChargeOutcome::notAttempted(\sprintf(
                'The "%s" payment method is not configured to charge renewals.',
                $method?->getCode() ?? '',
            ));
        }

        $paymentRequest = $this->paymentRequestFactory->create($payment, $method);
        $paymentRequest->setAction($action);
        $this->paymentRequestRepository->add($paymentRequest);

        $this->paymentRequestAnnouncer->dispatchPaymentRequestCommand($paymentRequest);

        return match (true) {
            PaymentInterface::STATE_COMPLETED === $payment->getState() => ChargeOutcome::approved(),
            \in_array($payment->getState(), [PaymentInterface::STATE_FAILED, PaymentInterface::STATE_CANCELLED], true),
            PaymentRequestInterface::STATE_FAILED === $paymentRequest->getState() => ChargeOutcome::declined(self::reasonOf($paymentRequest), self::codeOf($paymentRequest)),
            default => ChargeOutcome::unknown(),
        };
    }

    /** The gateway's own words when its response carries them; gateways name the key differently. */
    private static function reasonOf(PaymentRequestInterface $paymentRequest): ?string
    {
        return self::firstTextOf($paymentRequest, ['reason', 'message', 'error', 'responsetext']);
    }

    /**
     * The gateway's code for the decline, when its handler reports one. None of the official gateway
     * plugins does yet, so this is the key a handler sets for the retry policy to read it.
     */
    private static function codeOf(PaymentRequestInterface $paymentRequest): ?string
    {
        $code = self::firstTextOf($paymentRequest, ['code', 'decline_code', 'error_code']);

        return null === $code ? null : mb_substr($code, 0, 64);
    }

    /** @param list<string> $keys */
    private static function firstTextOf(PaymentRequestInterface $paymentRequest, array $keys): ?string
    {
        $response = $paymentRequest->getResponseData();
        foreach ($keys as $key) {
            if (isset($response[$key]) && \is_string($response[$key]) && '' !== $response[$key]) {
                return $response[$key];
            }
        }

        return null;
    }
}
