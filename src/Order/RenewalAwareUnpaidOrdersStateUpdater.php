<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Order;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\Exception\StateMachineExecutionException;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Core\Updater\UnpaidOrdersStateUpdaterInterface;
use Sylius\Component\Order\OrderTransitions;
use Webmozart\Assert\Assert;

/**
 * Takes the place of Sylius's cancellation of orders left unpaid, run by sylius:cancel-unpaid-orders,
 * and does the same, except for the renewal orders whose cycle still awaits payment: their retry
 * policy decides when to give up on them, and failing the cycle cancels them.
 *
 * They are left out of the search itself. Sylius searches again until nothing is left, so an order
 * skipped in the loop, or whose cancellation a guard refused, would be found again for ever; for the
 * same reason an order whose cancellation fails is not searched for again in this run.
 */
final class RenewalAwareUnpaidOrdersStateUpdater implements UnpaidOrdersStateUpdaterInterface
{
    /**
     * @param class-string<OrderInterface> $orderClass
     * @param class-string<SubscriptionCycleInterface> $cycleClass
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StateMachineInterface $stateMachine,
        private readonly LoggerInterface $logger,
        private readonly string $expirationPeriod,
        private readonly string $orderClass,
        private readonly string $cycleClass,
        private readonly int $batchSize = 100,
    ) {
    }

    public function cancel(): void
    {
        $failed = [];
        while ([] !== $orders = $this->findExpiredUnpaidOrders($failed)) {
            foreach ($orders as $order) {
                if (!$this->cancelOrder($order)) {
                    $failed[] = $order->getId();
                }
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
        }
    }

    /**
     * Sylius's own criteria, and its clock: an order's checkout completion date is set with the time
     * of the server.
     *
     * @param list<mixed> $excludedIds
     *
     * @return list<OrderInterface>
     */
    private function findExpiredUnpaidOrders(array $excludedIds): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from($this->orderClass, 'o')
            ->andWhere('o.checkoutState = :checkoutState')
            ->andWhere('o.paymentState = :paymentState')
            ->andWhere('o.state = :orderState')
            ->andWhere('o.checkoutCompletedAt < :terminalDate')
            // A renewal order waiting for a retry is left to the retry policy; one of a customer's
            // recovery, which the plugin never charges and so has no retry date, expires like any other.
            ->andWhere(\sprintf('NOT EXISTS (SELECT cycle.id FROM %s cycle WHERE cycle.order = o AND cycle.state = :awaitingPayment AND cycle.nextAttemptAt IS NOT NULL)', $this->cycleClass))
            ->setParameter('checkoutState', OrderCheckoutStates::STATE_COMPLETED)
            ->setParameter('paymentState', OrderPaymentStates::STATE_AWAITING_PAYMENT)
            ->setParameter('orderState', OrderInterface::STATE_NEW)
            ->setParameter('terminalDate', new \DateTime('-' . $this->expirationPeriod))
            ->setParameter('awaitingPayment', SubscriptionCycleInterface::STATE_AWAITING_PAYMENT)
            ->setMaxResults($this->batchSize)
        ;
        if ([] !== $excludedIds) {
            $queryBuilder->andWhere('o.id NOT IN (:excludedIds)')->setParameter('excludedIds', $excludedIds);
        }

        $orders = $queryBuilder->getQuery()->getResult();
        Assert::isList($orders);
        Assert::allIsInstanceOf($orders, OrderInterface::class);

        return $orders;
    }

    private function cancelOrder(OrderInterface $order): bool
    {
        try {
            $this->stateMachine->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);

            return true;
        } catch (StateMachineExecutionException $exception) {
            $this->logger->error(
                \sprintf('An error occurred while cancelling unpaid order #%s', $order->getId()),
                ['exception' => $exception, 'message' => $exception->getMessage()],
            );

            return false;
        }
    }
}
