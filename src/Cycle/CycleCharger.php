<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Cycle;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionChargeAttemptInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\EventPublisher;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalChargeDeclined;
use JpmMartin\SyliusSubscriptionPlugin\Payment\ChargeOutcome;
use JpmMartin\SyliusSubscriptionPlugin\Payment\RenewalChargerInterface;
use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Webmozart\Assert\Assert;

/**
 * Retries go to the order's latest new payment: Sylius opens one whenever a payment fails. Whether and
 * when a failed charge is retried is the retry policy's call.
 */
final class CycleCharger implements CycleChargerInterface
{
    /** @param FactoryInterface<SubscriptionChargeAttemptInterface> $attemptFactory */
    public function __construct(
        private readonly RenewalChargerInterface $renewalCharger,
        private readonly FactoryInterface $attemptFactory,
        private readonly CycleFailureHandlerInterface $failureHandler,
        private readonly RetryPolicyInterface $retryPolicy,
        private readonly ClockInterface $clock,
        private readonly EventPublisher $eventPublisher,
    ) {
    }

    public function charge(SubscriptionCycleInterface $cycle): ChargeOutcome
    {
        $payment = $cycle->getOrder()?->getLastPayment(PaymentInterface::STATE_NEW);
        $attempt = $this->start($cycle, SubscriptionChargeAttemptInterface::TYPE_CHARGE, $payment);
        $outcome = null === $payment
            ? ChargeOutcome::notAttempted('The order has no payment left to charge.')
            : $this->renewalCharger->charge($payment);

        self::finish($attempt, $outcome);
        $this->follow($cycle, $outcome);

        return $outcome;
    }

    public function reconcile(SubscriptionCycleInterface $cycle): ChargeOutcome
    {
        $payment = null;
        foreach ($cycle->getAttempts() as $attempt) {
            if (SubscriptionChargeAttemptInterface::TYPE_CHARGE === $attempt->getType()) {
                $payment = $attempt->getPayment();
            }
        }
        Assert::notNull($payment, 'Only a cycle that was charged can be reconciled.');

        $attempt = $this->start($cycle, SubscriptionChargeAttemptInterface::TYPE_STATUS, $payment);
        $outcome = $this->renewalCharger->status($payment);

        self::finish($attempt, $outcome);
        $this->follow($cycle, $outcome);

        return $outcome;
    }

    /**
     * The attempt is on the cycle before the gateway is asked: a gateway that answers at once completes
     * the payment during the charge, and the order's payment listeners must see it is the plugin's.
     */
    private function start(SubscriptionCycleInterface $cycle, string $type, ?PaymentInterface $payment): SubscriptionChargeAttemptInterface
    {
        $attempt = $this->attemptFactory->createNew();
        Assert::isInstanceOf($attempt, SubscriptionChargeAttemptInterface::class);
        $attempt->setType($type);
        $attempt->setOutcome(SubscriptionChargeAttemptInterface::OUTCOME_UNKNOWN);
        $attempt->setAttemptedAt($this->clock->now());
        $attempt->setPayment($payment);
        $cycle->addAttempt($attempt);

        return $attempt;
    }

    private static function finish(SubscriptionChargeAttemptInterface $attempt, ChargeOutcome $outcome): void
    {
        $attempt->setOutcome($outcome->outcome);
        $attempt->setReason($outcome->reason);
        $attempt->setCode($outcome->code);
    }

    private function follow(SubscriptionCycleInterface $cycle, ChargeOutcome $outcome): void
    {
        if ($outcome->isApproved()) {
            $cycle->setNextAttemptAt(null);

            return;
        }

        if ($outcome->isUnknown()) {
            $cycle->setNextAttemptAt($this->clock->now());

            return;
        }

        // An administrator's retry is charged once: the administrator decides whether to try again.
        if ($cycle->isManualRetry()) {
            $this->failureHandler->fail($cycle, $outcome->reason ?? 'The renewal could not be charged.');

            return;
        }

        $nextAttemptAt = $this->retryPolicy->nextAttemptAt($cycle, $outcome);
        if (null === $nextAttemptAt) {
            $this->failureHandler->fail($cycle, $outcome->reason ?? 'The renewal could not be charged.');

            return;
        }

        $cycle->setNextAttemptAt($nextAttemptAt);

        // Publishing never stops a charge: a cycle not stored yet has no identifier to carry.
        $subscriptionId = $cycle->getSubscription()?->getId();
        $cycleId = $cycle->getId();
        $orderId = $cycle->getOrder()?->getId();
        if (null === $subscriptionId || null === $cycleId || null === $orderId) {
            return;
        }
        $this->eventPublisher->publish(new RenewalChargeDeclined(
            $subscriptionId,
            $cycleId,
            $cycle->getNumber(),
            (int) $orderId,
            $nextAttemptAt,
            $outcome->reason,
            $outcome->code,
        ));
    }
}
