<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Cycle;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

/** The second cycle of a monthly batch of Coffee and Tea, due on 1 February, charged while the gateway does not answer. */
final class ReconcilingUnknownChargesTest extends LifecycleTestCase
{
    private int $subscriptionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedBatchOrder());
        $this->subscriptionId = (int) $this->subscriptionsByPlan()['COFFEE_MONTHLY+TEA_MONTHLY']->getId();

        $this->scriptedGateway()->willAnswer(ScriptedGateway::NO_ANSWER);
        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();
    }

    public function testAnUnknownChargeIsAskedAboutOnEveryRunAndNeverChargedAgainUntilTheGatewayReportsItPaid(): void
    {
        $this->scriptedGateway()->willAnswer(ScriptedGateway::NO_ANSWER);
        $this->itIsNow('2027-02-01 10:00');
        $this->runTheCycleCommand();
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $this->secondCycle()->getState());

        $this->itIsNow('2027-02-01 11:00');
        $this->runTheCycleCommand();

        self::assertSame(['capture', 'status', 'status'], $this->scriptedGateway()->requests());
        $second = $this->secondCycle();
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $second->getState());
        self::assertSame([['charge', 'unknown'], ['status', 'unknown'], ['status', 'approved']], $this->attemptsOf($second));
        self::assertSame(OrderPaymentStates::STATE_PAID, $second->getOrder()?->getPaymentState());
    }

    public function testAnUnknownChargeTheGatewayThenReportsDeclinedIsRetriedLikeAnyDecline(): void
    {
        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Do not honour.');
        $this->itIsNow('2027-02-01 10:00');
        $this->runTheCycleCommand();

        $second = $this->secondCycle();
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $second->getState());
        self::assertSame([['charge', 'unknown'], ['status', 'declined']], $this->attemptsOf($second));
        self::assertSame('2027-02-02 09:00', $second->getNextAttemptAt()?->format('Y-m-d H:i'), 'One day after the first attempt.');

        $this->itIsNow('2027-02-02 09:00');
        $this->runTheCycleCommand();

        self::assertSame(['capture', 'status', 'capture'], $this->scriptedGateway()->requests());
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $this->secondCycle()->getState());
    }

    private function secondCycle(): SubscriptionCycleInterface
    {
        /** @var RepositoryInterface<SubscriptionInterface> $subscriptions */
        $subscriptions = self::getContainer()->get('jpm_martin_sylius_subscription.repository.subscription');
        $subscription = $subscriptions->find($this->subscriptionId);
        self::assertInstanceOf(SubscriptionInterface::class, $subscription);

        return $this->storedCycles($subscription)[1];
    }

    /** @return list<array{string, string}> type and outcome of each attempt */
    private function attemptsOf(SubscriptionCycleInterface $cycle): array
    {
        $attempts = [];
        foreach ($cycle->getAttempts() as $attempt) {
            $attempts[] = [$attempt->getType(), $attempt->getOutcome()];
        }

        return $attempts;
    }
}
