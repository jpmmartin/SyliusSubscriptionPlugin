<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\EventListener\Workflow\Order;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionChargeAttemptInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\EventPublisher;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionPaymentMethodChanged;
use JpmMartin\SyliusSubscriptionPlugin\Payment\PluginCharges;
use JpmMartin\SyliusSubscriptionPlugin\Payment\RenewalChargerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionCycleRepositoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionCycleTransitions;
use Psr\Clock\ClockInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Webmozart\Assert\Assert;

/**
 * A renewal order its customer paid, on the store's order payment page, instead of the plugin charging
 * it: the cycle's history says so, and the subscription renews from then on with the method the
 * customer paid with, when the plugin can charge it. It runs before PayCycleListener pays the cycle.
 *
 * A payment is the plugin's when one of its attempts carries it; see PluginCharges. One an
 * administrator marks complete in the admin is not the customer's either, and is left out of the
 * history; the cycle is paid all the same.
 */
final class RecordCustomerPaymentListener
{
    /**
     * @param SubscriptionCycleRepositoryInterface<SubscriptionCycleInterface> $cycleRepository
     * @param FactoryInterface<SubscriptionChargeAttemptInterface> $attemptFactory
     * @param \Closure(): RenewalChargerInterface $renewalCharger only called when the customer paid
     */
    public function __construct(
        private readonly SubscriptionCycleRepositoryInterface $cycleRepository,
        private readonly StateMachineInterface $stateMachine,
        private readonly FactoryInterface $attemptFactory,
        private readonly \Closure $renewalCharger,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly ClockInterface $clock,
        private readonly EventPublisher $eventPublisher,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $order = $event->getSubject();
        Assert::isInstanceOf($order, OrderInterface::class);

        $cycle = $this->cycleRepository->findOneByOrder($order);
        $payment = $order->getLastPayment(PaymentInterface::STATE_COMPLETED);
        if (
            null === $cycle ||
            null === $payment ||
            !$this->stateMachine->can($cycle, SubscriptionCycleTransitions::GRAPH, SubscriptionCycleTransitions::TRANSITION_PAY) ||
            PluginCharges::include($cycle, $payment) ||
            $this->tokenStorage->getToken()?->getUser() instanceof AdminUserInterface
        ) {
            return;
        }

        $attempt = $this->attemptFactory->createNew();
        $attempt->setType(SubscriptionChargeAttemptInterface::TYPE_CUSTOMER);
        $attempt->setOutcome(SubscriptionChargeAttemptInterface::OUTCOME_APPROVED);
        $attempt->setAttemptedAt($this->clock->now());
        $attempt->setPayment($payment);
        $cycle->addAttempt($attempt);

        $subscription = $cycle->getSubscription();
        $method = $payment->getMethod();
        if (
            null === $subscription ||
            !$method instanceof PaymentMethodInterface ||
            $method === $subscription->getPaymentMethod() ||
            !($this->renewalCharger)()->supports($method)
        ) {
            return;
        }

        $subscription->setPaymentMethod($method);
        $subscriptionId = $subscription->getId();
        if (null !== $subscriptionId) {
            $this->eventPublisher->publish(new SubscriptionPaymentMethodChanged($subscriptionId, (string) $method->getCode()));
        }
    }
}
