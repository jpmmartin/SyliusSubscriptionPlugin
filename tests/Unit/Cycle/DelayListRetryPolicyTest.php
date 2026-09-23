<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Unit\Cycle;

use JpmMartin\SyliusSubscriptionPlugin\Cycle\DelayListRetryPolicy;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionChargeAttempt;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionChargeAttemptInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycle;
use JpmMartin\SyliusSubscriptionPlugin\Payment\ChargeOutcome;
use PHPUnit\Framework\TestCase;

final class DelayListRetryPolicyTest extends TestCase
{
    public function testEachFailureIsRetriedAfterItsDelayCountedFromTheFirstAttemptUntilTheListRunsOut(): void
    {
        $policy = new DelayListRetryPolicy([1, 3, 7], []);
        $cycle = new SubscriptionCycle();

        $this->attempt($cycle, '2027-02-01 09:00', SubscriptionChargeAttemptInterface::OUTCOME_DECLINED);
        self::assertSame('2027-02-02 09:00', $policy->nextAttemptAt($cycle, ChargeOutcome::declined())?->format('Y-m-d H:i'));

        $this->attempt($cycle, '2027-02-02 09:00', SubscriptionChargeAttemptInterface::OUTCOME_NOT_ATTEMPTED);
        self::assertSame('2027-02-04 09:00', $policy->nextAttemptAt($cycle, ChargeOutcome::notAttempted('Card expired.'))?->format('Y-m-d H:i'));

        $this->attempt($cycle, '2027-02-04 09:00', SubscriptionChargeAttemptInterface::OUTCOME_DECLINED);
        self::assertSame('2027-02-08 09:00', $policy->nextAttemptAt($cycle, ChargeOutcome::declined())?->format('Y-m-d H:i'));

        $this->attempt($cycle, '2027-02-08 09:00', SubscriptionChargeAttemptInterface::OUTCOME_DECLINED);
        self::assertNull($policy->nextAttemptAt($cycle, ChargeOutcome::declined()));
    }

    public function testAnUnknownOutcomeInTheHistoryIsNotAFailure(): void
    {
        $policy = new DelayListRetryPolicy([1, 3], []);
        $cycle = new SubscriptionCycle();
        $this->attempt($cycle, '2027-02-01 09:00', SubscriptionChargeAttemptInterface::OUTCOME_UNKNOWN);
        $this->attempt($cycle, '2027-02-01 10:00', SubscriptionChargeAttemptInterface::OUTCOME_DECLINED);

        self::assertSame('2027-02-02 09:00', $policy->nextAttemptAt($cycle, ChargeOutcome::declined())?->format('Y-m-d H:i'));
    }

    public function testADeclineWithAFinalCodeFailsTheCycleAtOnceAndAnyOtherCodeIsRetried(): void
    {
        $policy = new DelayListRetryPolicy([1, 3, 7], ['stolen_card']);
        $cycle = new SubscriptionCycle();
        $this->attempt($cycle, '2027-02-01 09:00', SubscriptionChargeAttemptInterface::OUTCOME_DECLINED);

        self::assertNull($policy->nextAttemptAt($cycle, ChargeOutcome::declined('Stolen card.', 'stolen_card')));
        self::assertNotNull($policy->nextAttemptAt($cycle, ChargeOutcome::declined('Stolen card.', 'Stolen_Card')), 'Codes are compared exactly.');
        self::assertNotNull($policy->nextAttemptAt($cycle, ChargeOutcome::declined('Insufficient funds.', 'insufficient_funds')));
    }

    public function testWithoutDelaysNothingIsRetried(): void
    {
        $policy = new DelayListRetryPolicy([], []);
        $cycle = new SubscriptionCycle();
        $this->attempt($cycle, '2027-02-01 09:00', SubscriptionChargeAttemptInterface::OUTCOME_DECLINED);

        self::assertNull($policy->nextAttemptAt($cycle, ChargeOutcome::declined()));
    }

    private function attempt(SubscriptionCycle $cycle, string $at, string $outcome): void
    {
        $attempt = new SubscriptionChargeAttempt();
        $attempt->setType(SubscriptionChargeAttemptInterface::TYPE_CHARGE);
        $attempt->setOutcome($outcome);
        $attempt->setAttemptedAt(new \DateTimeImmutable($at));
        $cycle->addAttempt($attempt);
    }
}
