<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Management;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionReactivated;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionSuspended;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionRecoveryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Payment\RenewalPaymentLinkGeneratorInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Sylius\Bundle\PaymentBundle\Announcer\PaymentRequestAnnouncerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Factory\PaymentRequestFactoryInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Event\EventCollector;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

/**
 * A monthly Coffee subscription activated on 1 December. With the default retries, 1, 3 and 7 days
 * after a renewal is first declined, a renewal declined every time fails on the 8th of its month, and
 * the third in a row suspends the subscription.
 */
final class RecoveringASuspendedSubscriptionTest extends LifecycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->itIsNow('2026-12-01 09:00');
        $this->pay($this->placedCoffeeOrder());
        $this->collector()->clear();
    }

    public function testCyclesFailedInARowSuspendTheSubscriptionForThemAndSaySo(): void
    {
        $this->theRenewalsOfJanuaryFebruaryAndMarchFail();

        $subscription = $this->subscription();
        self::assertSame(SubscriptionInterface::STATE_SUSPENDED, $subscription->getState());
        self::assertTrue($subscription->isSuspendedForFailedCycles());
        self::assertEquals(
            [new SubscriptionSuspended((int) $subscription->getId(), true)],
            $this->collector()->events(SubscriptionSuspended::class),
        );
    }

    public function testAnAdministratorsSuspensionIsNotForFailedCycles(): void
    {
        $subscription = $this->subscription();
        $this->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_SUSPEND);
        $this->entityManager()->flush();

        $subscription = $this->subscription();
        self::assertSame(SubscriptionInterface::STATE_SUSPENDED, $subscription->getState());
        self::assertFalse($subscription->isSuspendedForFailedCycles());
        self::assertEquals(
            [new SubscriptionSuspended((int) $subscription->getId(), false)],
            $this->collector()->events(SubscriptionSuspended::class),
        );
    }

    public function testReactivatingClearsTheCause(): void
    {
        $this->theRenewalsOfJanuaryFebruaryAndMarchFail();

        $this->itIsNow('2027-03-10 09:00');
        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_REACTIVATE);
        $this->entityManager()->flush();

        $subscription = $this->subscription();
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState());
        self::assertFalse($subscription->isSuspendedForFailedCycles());
    }

    public function testPayingTheRecoveryChargesTheLastFailedCycleAndReactivatesTheSubscription(): void
    {
        $this->theRenewalsOfJanuaryFebruaryAndMarchFail();
        $march = $this->cycleOf('2027-03-01 09:00');
        $failedOrderId = $march->getOrder()?->getId();

        $this->itIsNow('2027-03-10 09:00');
        self::assertTrue($this->recovery()->canRecover($this->subscription()));
        $order = $this->recovery()->start($this->subscription());
        $this->entityManager()->flush();
        self::assertNotSame($failedOrderId, $order->getId(), 'A new order for the cycle.');
        $this->collector()->clear();

        $this->customerPays($order);

        $march = $this->cycleOf('2027-03-01 09:00');
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $march->getState());
        self::assertSame($order->getId(), $march->getOrder()?->getId());
        $subscription = $this->subscription();
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState());
        self::assertSame(0, $subscription->getConsecutiveFailedCycles());
        self::assertFalse($subscription->isSuspendedForFailedCycles());
        self::assertSame(SubscriptionCycleInterface::STATE_SCHEDULED, $this->cycleOf('2027-04-01 09:00')->getState());
        self::assertEquals(
            [new SubscriptionReactivated((int) $subscription->getId())],
            $this->collector()->events(SubscriptionReactivated::class),
        );
    }

    public function testStartingTheRecoveryAgainBeforePayingGivesTheSameOrder(): void
    {
        $this->theRenewalsOfJanuaryFebruaryAndMarchFail();
        $this->itIsNow('2027-03-10 09:00');
        $first = $this->recovery()->start($this->subscription());
        $this->entityManager()->flush();

        $this->itIsNow('2027-03-11 09:00');
        self::assertTrue($this->recovery()->canRecover($this->subscription()));
        $second = $this->recovery()->start($this->subscription());
        $this->entityManager()->flush();

        self::assertSame($first->getId(), $second->getId());
        self::assertSame(1, $this->countOrdersOf($this->cycleOf('2027-03-01 09:00')));
    }

    public function testASubscriptionAnAdministratorSuspendedCannotBeRecoveredByItsCustomer(): void
    {
        $this->theRenewalsOfJanuaryFebruaryAndMarchFail();
        $subscription = $this->subscription();
        $subscription->setSuspendedForFailedCycles(false);
        $this->entityManager()->flush();
        $ordersBefore = $this->countOrders();

        self::assertFalse($this->recovery()->canRecover($this->subscription()));
        self::assertNull($this->recovery()->whyNot($this->subscription()));

        try {
            $this->recovery()->start($this->subscription());
            self::fail('A suspension by an administrator was lifted by the customer.');
        } catch (\InvalidArgumentException) {
        }
        $this->entityManager()->flush();

        self::assertSame(SubscriptionCycleInterface::STATE_FAILED, $this->cycleOf('2027-03-01 09:00')->getState());
        self::assertSame($ordersBefore, $this->countOrders());
    }

    public function testARecoveryPaidAfterAnAdministratorSuspendedTheSubscriptionAgainLeavesItSuspended(): void
    {
        $this->theRenewalsOfJanuaryFebruaryAndMarchFail();
        $this->itIsNow('2027-03-10 09:00');
        $order = $this->recovery()->start($this->subscription());
        $this->entityManager()->flush();
        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_REACTIVATE);
        $this->entityManager()->flush();
        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_SUSPEND);
        $this->entityManager()->flush();
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $this->cycleOf('2027-03-01 09:00')->getState(), 'The suspension leaves the retry alone.');

        $this->customerPays($order);

        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $this->cycleOf('2027-03-01 09:00')->getState());
        self::assertSame(SubscriptionInterface::STATE_SUSPENDED, $this->subscription()->getState(), 'The administrator\'s suspension stands.');
    }

    public function testTheStoreGetsTheLinkThatRecoversItOnlyWhileItsCustomerCan(): void
    {
        $this->theRenewalsOfJanuaryFebruaryAndMarchFail();
        $subscription = $this->subscription();
        /** @var RenewalPaymentLinkGeneratorInterface $links */
        $links = self::getContainer()->get(RenewalPaymentLinkGeneratorInterface::class);

        $link = $links->generateRecovery($subscription);
        self::assertNotNull($link);
        self::assertStringEndsWith(\sprintf('/en_US/account/subscriptions/%d/recover', (int) $subscription->getId()), $link);
        self::assertStringStartsWith('http', $link);

        $subscription->setSuspendedForFailedCycles(false);
        $this->entityManager()->flush();
        self::assertNull($links->generateRecovery($this->subscription()), 'An administrator\'s suspension.');
    }

    public function testNothingIsOfferedWhenNoneOfItsProductsCanBeSold(): void
    {
        $this->theRenewalsOfJanuaryFebruaryAndMarchFail();
        $coffee = $this->entityManager()->find($this->coffee::class, $this->coffee->getId());
        self::assertNotNull($coffee);
        $coffee->setEnabled(false);
        $this->entityManager()->flush();

        self::assertFalse($this->recovery()->canRecover($this->subscription()));
        self::assertSame(SubscriptionRecoveryInterface::NOTHING_TO_RENEW, $this->recovery()->whyNot($this->subscription()));
    }

    public function testTheCyclesCommandNeverChargesTheRecoveryOrder(): void
    {
        $this->theRenewalsOfJanuaryFebruaryAndMarchFail();
        $this->itIsNow('2027-03-10 09:00');
        $this->recovery()->start($this->subscription());
        $this->entityManager()->flush();
        $requestsBefore = \count($this->scriptedGateway()->requests());

        foreach (['2027-03-10 10:00', '2027-03-11 09:00', '2027-03-17 09:00'] as $dateTime) {
            $this->itIsNow($dateTime);
            $this->runTheCycleCommand();
        }

        self::assertCount($requestsBefore, $this->scriptedGateway()->requests());
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $this->cycleOf('2027-03-01 09:00')->getState());
        self::assertSame(SubscriptionInterface::STATE_SUSPENDED, $this->subscription()->getState());
    }

    public function testARecoveryOrderLeftUnpaidExpiresAndItsCycleFailsAgain(): void
    {
        $this->theRenewalsOfJanuaryFebruaryAndMarchFail();
        $this->itIsNow('2027-03-10 09:00');
        $order = $this->recovery()->start($this->subscription());
        $this->entityManager()->flush();

        $this->completedDaysAgo($order, 6);
        $this->runTheUnpaidOrdersCommand();

        $order = $this->entityManager()->find($order::class, $order->getId());
        self::assertInstanceOf(OrderInterface::class, $order);
        self::assertSame(OrderInterface::STATE_CANCELLED, $order->getState());
        self::assertSame(SubscriptionCycleInterface::STATE_FAILED, $this->cycleOf('2027-03-01 09:00')->getState());
        $subscription = $this->subscription();
        self::assertSame(SubscriptionInterface::STATE_SUSPENDED, $subscription->getState());
        self::assertSame(3, $subscription->getConsecutiveFailedCycles(), 'Not counted twice.');
        self::assertTrue($this->recovery()->canRecover($subscription), 'It can be recovered again.');
    }

    /** As the store's order payment page does: a payment request of the pending payment, which the test gateway approves. */
    private function customerPays(OrderInterface $order): void
    {
        $order = $this->entityManager()->find($order::class, $order->getId());
        self::assertInstanceOf(OrderInterface::class, $order);
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);

        /** @var PaymentRequestFactoryInterface<PaymentRequestInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.payment_request');
        $paymentRequest = $factory->create($payment, $method);
        $paymentRequest->setAction(PaymentRequestInterface::ACTION_CAPTURE);
        /** @var RepositoryInterface<PaymentRequestInterface> $repository */
        $repository = self::getContainer()->get('sylius.repository.payment_request');
        $repository->add($paymentRequest);
        /** @var PaymentRequestAnnouncerInterface $announcer */
        $announcer = self::getContainer()->get(PaymentRequestAnnouncerInterface::class);
        $announcer->dispatchPaymentRequestCommand($paymentRequest);
        $this->entityManager()->flush();
    }

    private function completedDaysAgo(OrderInterface $order, int $days): void
    {
        $order = $this->entityManager()->find($order::class, $order->getId());
        self::assertInstanceOf(OrderInterface::class, $order);
        $order->setCheckoutCompletedAt(new \DateTime(\sprintf('-%d days', $days)));
        $this->entityManager()->flush();
    }

    private function runTheUnpaidOrdersCommand(): void
    {
        $this->entityManager()->clear();

        $application = new Application(self::$kernel ?? self::bootKernel());
        $tester = new CommandTester($application->find('sylius:cancel-unpaid-orders'));
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
    }

    private function countOrders(): int
    {
        return (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM sylius_order');
    }

    private function countOrdersOf(SubscriptionCycleInterface $cycle): int
    {
        return (int) $this->entityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM sylius_order WHERE id = ?',
            [$cycle->getOrder()?->getId()],
        );
    }

    private function recovery(): SubscriptionRecoveryInterface
    {
        $recovery = self::getContainer()->get(SubscriptionRecoveryInterface::class);
        self::assertInstanceOf(SubscriptionRecoveryInterface::class, $recovery);

        return $recovery;
    }

    private function theRenewalsOfJanuaryFebruaryAndMarchFail(): void
    {
        foreach (['2027-01', '2027-02', '2027-03'] as $month) {
            $this->declinedUntilItFails($month . '-01 09:00');
        }
        self::assertSame(SubscriptionInterface::STATE_SUSPENDED, $this->subscription()->getState());
    }

    /** The renewal of that date and each of its retries are declined, so it fails a week later. */
    private function declinedUntilItFails(string $dateTime): void
    {
        foreach ([0, 1, 3, 7] as $days) {
            $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
            $this->itIsNow((new \DateTimeImmutable($dateTime))->modify(\sprintf('+%d days', $days))->format('Y-m-d H:i'));
            $this->runTheCycleCommand();
        }
        self::assertSame(SubscriptionCycleInterface::STATE_FAILED, $this->cycleOf($dateTime)->getState());
    }

    private function cycleOf(string $dateTime): SubscriptionCycleInterface
    {
        foreach ($this->storedCycles($this->subscription()) as $cycle) {
            if ($dateTime === $cycle->getScheduledAt()?->format('Y-m-d H:i')) {
                return $cycle;
            }
        }

        self::fail(\sprintf('The subscription has no cycle on %s.', $dateTime));
    }

    private function subscription(): SubscriptionInterface
    {
        return $this->subscriptionsByPlan()['COFFEE_MONTHLY'];
    }

    private function collector(): EventCollector
    {
        $collector = self::getContainer()->get('jpm_martin_sylius_subscription.test.event_collector');
        self::assertInstanceOf(EventCollector::class, $collector);

        return $collector;
    }
}
