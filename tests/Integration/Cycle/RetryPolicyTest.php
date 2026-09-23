<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Cycle;

use JpmMartin\SyliusSubscriptionPlugin\Cycle\DelayListRetryPolicy;
use JpmMartin\SyliusSubscriptionPlugin\Cycle\RetryPolicyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Payment\ChargeOutcome;
use Sylius\Component\Core\Model\OrderInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

/**
 * A monthly Coffee subscription activated on 1 January, whose February cycle is charged on the 1st.
 * The test store declares "stolen_card" a final decline and keeps the default delays.
 */
final class RetryPolicyTest extends LifecycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedCoffeeOrder());
    }

    public function testADeclineWithACodeTheStoreDeclaresFinalFailsTheCycleAtOnce(): void
    {
        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Stolen card.', 'stolen_card');
        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();

        [, $february, $march] = $this->storedCycles($this->subscription());
        self::assertSame(SubscriptionCycleInterface::STATE_FAILED, $february->getState());
        self::assertSame('Stolen card.', $february->getCancellationReason());
        self::assertSame(OrderInterface::STATE_CANCELLED, $february->getOrder()?->getState());
        self::assertSame(SubscriptionCycleInterface::STATE_SCHEDULED, $march->getState());
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $this->subscription()->getState());

        $this->itIsNow('2027-02-02 09:00');
        $this->runTheCycleCommand();
        self::assertSame(['capture'], $this->scriptedGateway()->requests(), 'No retry was scheduled.');
    }

    public function testADeclineWithAnyOtherCodeIsRetried(): void
    {
        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.', 'insufficient_funds');
        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();

        $february = $this->storedCycles($this->subscription())[1];
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $february->getState());
        self::assertSame('2027-02-02 09:00', $february->getNextAttemptAt()?->format('Y-m-d H:i'));
    }

    public function testWithoutDelaysTheFirstDeclineFailsTheCycle(): void
    {
        self::getContainer()->set('jpm_martin_sylius_subscription.cycle.retry_policy', new DelayListRetryPolicy([], []));
        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
        $this->itIsNow('2027-02-01 09:00');

        $this->runTheCycleCommand();

        $february = $this->storedCycles($this->subscription())[1];
        self::assertSame(SubscriptionCycleInterface::STATE_FAILED, $february->getState());
        self::assertCount(1, $february->getAttempts());
    }

    public function testAStoresOwnPolicyDecidesInstead(): void
    {
        $asked = new \ArrayObject();
        self::getContainer()->set('jpm_martin_sylius_subscription.cycle.retry_policy', new class($asked) implements RetryPolicyInterface {
            /** @param \ArrayObject<int, string|null> $asked */
            public function __construct(private readonly \ArrayObject $asked)
            {
            }

            public function nextAttemptAt(SubscriptionCycleInterface $cycle, ChargeOutcome $outcome): ?\DateTimeImmutable
            {
                $this->asked->append($outcome->code);

                // Retried twelve hours after the first decline, and never again.
                return 1 === $cycle->getAttempts()->count()
                    ? $cycle->getAttempts()->first()?->getAttemptedAt()?->modify('+12 hours')
                    : null;
            }
        });

        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.', 'insufficient_funds');
        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();
        self::assertSame('2027-02-01 21:00', $this->storedCycles($this->subscription())[1]->getNextAttemptAt()?->format('Y-m-d H:i'));

        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.', 'insufficient_funds');
        $this->itIsNow('2027-02-01 21:00');
        $this->runTheCycleCommand();

        self::assertSame(SubscriptionCycleInterface::STATE_FAILED, $this->storedCycles($this->subscription())[1]->getState());
        self::assertSame(['insufficient_funds', 'insufficient_funds'], $asked->getArrayCopy());
    }

    private function subscription(): SubscriptionInterface
    {
        return $this->subscriptionsByPlan()['COFFEE_MONTHLY'];
    }
}
