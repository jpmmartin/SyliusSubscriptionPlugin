<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Payment;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionCycleRepositoryInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface as CorePaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface as CorePaymentMethodInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;

/**
 * On the payment of a renewal order, only the payment methods the plugin can charge without the
 * customer: whichever one the customer pays with there, the subscription renews with afterwards. Every
 * other payment gets what Sylius would give it.
 */
final class RenewalPaymentMethodsResolver implements PaymentMethodsResolverInterface
{
    /**
     * @param SubscriptionCycleRepositoryInterface<SubscriptionCycleInterface> $cycleRepository
     * @param \Closure(): RenewalChargerInterface $renewalCharger only called for a renewal order: every
     *     cart's processing resolves payment methods, and the charger is not needed there
     */
    public function __construct(
        private readonly PaymentMethodsResolverInterface $decoratedResolver,
        private readonly SubscriptionCycleRepositoryInterface $cycleRepository,
        private readonly \Closure $renewalCharger,
    ) {
    }

    public function getSupportedMethods(PaymentInterface $subject): array
    {
        $methods = $this->decoratedResolver->getSupportedMethods($subject);
        if (!$this->isOfARenewalOrder($subject)) {
            return $methods;
        }

        $renewalCharger = ($this->renewalCharger)();

        return array_values(array_filter(
            $methods,
            static fn (PaymentMethodInterface $method): bool => $method instanceof CorePaymentMethodInterface && $renewalCharger->supports($method),
        ));
    }

    public function supports(PaymentInterface $subject): bool
    {
        return $this->decoratedResolver->supports($subject);
    }

    /** A renewal order is placed complete: a cart never is one, so the checkout costs no query. */
    private function isOfARenewalOrder(PaymentInterface $payment): bool
    {
        $order = $payment instanceof CorePaymentInterface ? $payment->getOrder() : null;

        return $order instanceof OrderInterface &&
            null !== $order->getId() &&
            OrderCheckoutStates::STATE_COMPLETED === $order->getCheckoutState() &&
            null !== $this->cycleRepository->findOneByOrder($order);
    }
}
