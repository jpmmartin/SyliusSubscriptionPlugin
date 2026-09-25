<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Management;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Order\RenewalOrderPlacerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Order\VariantAvailabilityChecker;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionCycleTransitions;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Webmozart\Assert\Assert;

/**
 * The recovery is an administrator's retry the plugin does not charge: the cycle is retried and marked
 * so, and left with no attempt date, which keeps the cycles command away from it. No retry of the
 * plugin's ever stays waiting without a date: it is charged in the same run.
 */
final class SubscriptionRecovery implements SubscriptionRecoveryInterface
{
    public function __construct(
        private readonly StateMachineInterface $stateMachine,
        private readonly RenewalOrderPlacerInterface $renewalOrderPlacer,
        private readonly VariantAvailabilityChecker $availabilityChecker,
    ) {
    }

    public function canRecover(SubscriptionInterface $subscription): bool
    {
        if (!self::isSuspendedForUnpaidRenewals($subscription)) {
            return false;
        }

        return null !== $this->recoveryAwaitingPayment($subscription) ||
            (null !== $this->failedLastCycle($subscription) && $this->hasSomethingToRenew($subscription));
    }

    public function whyNot(SubscriptionInterface $subscription): ?string
    {
        if (
            self::isSuspendedForUnpaidRenewals($subscription) &&
            null !== $this->failedLastCycle($subscription) &&
            !$this->hasSomethingToRenew($subscription)
        ) {
            return self::NOTHING_TO_RENEW;
        }

        return null;
    }

    public function start(SubscriptionInterface $subscription): OrderInterface
    {
        Assert::true($this->canRecover($subscription), 'This subscription cannot be recovered by its customer now.');

        $awaiting = $this->recoveryAwaitingPayment($subscription);
        if (null !== $awaiting) {
            $order = $awaiting->getOrder();
            Assert::isInstanceOf($order, OrderInterface::class);

            return $order;
        }

        $cycle = $this->failedLastCycle($subscription);
        Assert::notNull($cycle);
        $this->stateMachine->apply($cycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_RETRY);
        $cycle->setManualRetry(true);
        $cycle->setCancellationReason(null);
        $cycle->setNextAttemptAt(null);

        $order = $this->renewalOrderPlacer->place($cycle);
        Assert::notNull($order, 'None of the subscription\'s items can be sold now.');

        return $order;
    }

    public function isAwaitingItsCustomer(SubscriptionCycleInterface $cycle): bool
    {
        return SubscriptionCycleInterface::STATE_AWAITING_PAYMENT === $cycle->getState() &&
            $cycle->isManualRetry() &&
            null === $cycle->getNextAttemptAt();
    }

    private static function isSuspendedForUnpaidRenewals(SubscriptionInterface $subscription): bool
    {
        return SubscriptionInterface::STATE_SUSPENDED === $subscription->getState() && $subscription->isSuspendedForUnpaidRenewals();
    }

    private function recoveryAwaitingPayment(SubscriptionInterface $subscription): ?SubscriptionCycleInterface
    {
        $cycle = self::lastCycleOf($subscription);
        if (null === $cycle || !$this->isAwaitingItsCustomer($cycle) || null === $cycle->getOrder()?->getLastPayment(PaymentInterface::STATE_NEW)) {
            return null;
        }

        return $cycle;
    }

    private function failedLastCycle(SubscriptionInterface $subscription): ?SubscriptionCycleInterface
    {
        $cycle = self::lastCycleOf($subscription);

        return null !== $cycle && $this->stateMachine->can($cycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_RETRY)
            ? $cycle
            : null;
    }

    private function hasSomethingToRenew(SubscriptionInterface $subscription): bool
    {
        $channel = $subscription->getChannel();
        if (!$channel instanceof ChannelInterface) {
            return false;
        }

        foreach ($subscription->getItems() as $item) {
            $variant = $item->getProductVariant();
            if ($item->isRenewable() && null !== $variant && $this->availabilityChecker->isAvailable($variant, $channel, $item->getQuantity())) {
                return true;
            }
        }

        return false;
    }

    private static function lastCycleOf(SubscriptionInterface $subscription): ?SubscriptionCycleInterface
    {
        $last = null;
        foreach ($subscription->getCycles() as $cycle) {
            if (null === $last || $cycle->getNumber() > $last->getNumber()) {
                $last = $cycle;
            }
        }

        return $last;
    }
}
