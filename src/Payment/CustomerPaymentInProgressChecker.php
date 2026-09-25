<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Payment;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;

/**
 * Whether the customer is paying a cycle's pending payment on the store's order payment page, so a
 * retry now could charge them twice. The plugin leaves no payment request of its own pending at a
 * retry: one whose outcome it did not learn is reconciled instead. So a payment request of the pending
 * payment still new or processing is the customer's, and it counts until it has been left untouched
 * for the wait: a payment the customer gave up on must not hold the retry back for good.
 *
 * Only gateways that use Sylius's payment requests leave one to find; with Payum, nothing is found.
 */
final class CustomerPaymentInProgressChecker
{
    /** @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository */
    public function __construct(
        private readonly PaymentRequestRepositoryInterface $paymentRequestRepository,
        private readonly ClockInterface $clock,
        private readonly int $waitMinutes,
    ) {
    }

    public function isInProgress(SubscriptionCycleInterface $cycle): bool
    {
        $payment = $cycle->getOrder()?->getLastPayment(PaymentInterface::STATE_NEW);
        $paymentId = $payment?->getId();
        if (null === $paymentId) {
            return false;
        }

        $since = \DateTimeImmutable::createFromInterface($this->clock->now())->modify(\sprintf('-%d minutes', $this->waitMinutes));
        $pending = $this->paymentRequestRepository->findByPaymentIdAndStates(
            $paymentId,
            [PaymentRequestInterface::STATE_NEW, PaymentRequestInterface::STATE_PROCESSING],
        );
        foreach ($pending as $paymentRequest) {
            $touchedAt = $paymentRequest->getUpdatedAt() ?? $paymentRequest->getCreatedAt();
            if (null !== $touchedAt && $touchedAt >= $since) {
                return true;
            }
        }

        return false;
    }
}
