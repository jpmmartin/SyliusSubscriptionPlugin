<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Cycle;

use JpmMartin\SyliusSubscriptionPlugin\Command\ProcessSubscriptionCycle;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionChargeAttemptInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionCycleRepositoryInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

/** A monthly batch of Coffee and Tea activated on 1 January, whose second cycle is due on 1 February. */
final class ProcessingDueCyclesTest extends LifecycleTestCase
{
    private int $subscriptionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedBatchOrder());
        $this->subscriptionId = (int) $this->subscriptionsByPlan()['COFFEE_MONTHLY+TEA_MONTHLY']->getId();

        $this->itIsNow('2027-02-01 09:00');
    }

    public function testRunningTheCommandTwiceOnADueCyclePlacesOneOrderOfEveryItemAndChargesItOnce(): void
    {
        $this->runTheCycleCommand();
        $this->runTheCycleCommand();

        self::assertSame(['capture'], $this->scriptedGateway()->requests());
        $cycles = $this->storedCycles($this->subscription());
        $second = $cycles[1];
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $second->getState());
        self::assertSame([SubscriptionChargeAttemptInterface::OUTCOME_APPROVED], $this->outcomesOf($second));
        self::assertSame(OrderPaymentStates::STATE_PAID, $second->getOrder()?->getPaymentState());
        self::assertCount(2, $second->getOrder()?->getItems() ?? []);
        self::assertSame(2, $this->countOrders(), 'The initial order and one renewal.');
        foreach ($this->subscription()->getItems() as $item) {
            self::assertSame(2, $item->getPaidCycles());
        }

        self::assertCount(3, $cycles);
        self::assertSame(SubscriptionCycleInterface::STATE_SCHEDULED, $cycles[2]->getState());
        self::assertSame('2027-03-01 09:00', $cycles[2]->getScheduledAt()?->format('Y-m-d H:i'));
    }

    public function testRunningTheCommandAgainAfterADeclineDoesNotChargeBeforeTheRetryIsDue(): void
    {
        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');

        $this->runTheCycleCommand();
        $this->runTheCycleCommand();

        self::assertSame(['capture'], $this->scriptedGateway()->requests());
        $second = $this->storedCycles($this->subscription())[1];
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $second->getState());
        self::assertSame([SubscriptionChargeAttemptInterface::OUTCOME_DECLINED], $this->outcomesOf($second));
        self::assertSame('2027-02-02 09:00', $second->getNextAttemptAt()?->format('Y-m-d H:i'));
        self::assertSame(2, $this->countOrders());
    }

    /**
     * After an unknown outcome the cycle is due again at once, so only its version tells a stale
     * delivery of the same message from the next run's.
     */
    public function testTheSameMessageDeliveredTwiceIsHandledOnce(): void
    {
        $this->scriptedGateway()->willAnswer(ScriptedGateway::NO_ANSWER);
        /** @var SubscriptionCycleRepositoryInterface<SubscriptionCycleInterface> $cycles */
        $cycles = self::getContainer()->get('jpm_martin_sylius_subscription.repository.subscription_cycle');
        $due = $cycles->findDue(new \DateTimeImmutable('2027-02-01 09:00'));
        self::assertCount(1, $due);
        $message = new ProcessSubscriptionCycle($due[0]['id'], $due[0]['version']);

        /** @var MessageBusInterface $commandBus */
        $commandBus = self::getContainer()->get('sylius.command_bus');
        $commandBus->dispatch($message);
        $this->entityManager()->clear();
        $commandBus->dispatch($message);

        self::assertSame(['capture'], $this->scriptedGateway()->requests());
        self::assertSame([SubscriptionChargeAttemptInterface::OUTCOME_UNKNOWN], $this->outcomesOf($this->storedCycles($this->subscription())[1]));
        self::assertSame(2, $this->countOrders());
    }

    public function testACycleThatIsNotDueYetIsLeftAlone(): void
    {
        $this->itIsNow('2027-01-31 23:59');

        $this->runTheCycleCommand();

        self::assertSame([], $this->scriptedGateway()->requests());
        $second = $this->storedCycles($this->subscription())[1];
        self::assertSame(SubscriptionCycleInterface::STATE_SCHEDULED, $second->getState());
        self::assertNull($second->getOrder());
        self::assertSame(1, $this->countOrders());
    }

    private function subscription(): SubscriptionInterface
    {
        /** @var RepositoryInterface<SubscriptionInterface> $subscriptions */
        $subscriptions = self::getContainer()->get('jpm_martin_sylius_subscription.repository.subscription');
        $subscription = $subscriptions->find($this->subscriptionId);
        self::assertInstanceOf(SubscriptionInterface::class, $subscription);

        return $subscription;
    }

    /** @return list<string> */
    private function outcomesOf(SubscriptionCycleInterface $cycle): array
    {
        $outcomes = [];
        foreach ($cycle->getAttempts() as $attempt) {
            $outcomes[] = $attempt->getOutcome();
        }

        return $outcomes;
    }

    private function countOrders(): int
    {
        /** @var RepositoryInterface<OrderInterface> $orders */
        $orders = self::getContainer()->get('sylius.repository.order');

        return \count($orders->findBy(['checkoutState' => 'completed']));
    }
}
