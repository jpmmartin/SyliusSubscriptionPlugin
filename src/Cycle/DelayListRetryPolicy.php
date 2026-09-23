<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Cycle;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionChargeAttemptInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Payment\ChargeOutcome;
use Webmozart\Assert\Assert;

/**
 * The delays count from the cycle's first attempt, so [1, 3, 7] retries one, three and seven days
 * after it, and the failure after the last delay fails the cycle. A decline whose code the store
 * declares final fails it at once: retrying a stolen card only earns more declines.
 */
final class DelayListRetryPolicy implements RetryPolicyInterface
{
    /**
     * @param list<int> $retryDelays in days after the first attempt
     * @param list<string> $finalDeclineCodes
     */
    public function __construct(
        private readonly array $retryDelays,
        private readonly array $finalDeclineCodes,
    ) {
    }

    public function nextAttemptAt(SubscriptionCycleInterface $cycle, ChargeOutcome $outcome): ?\DateTimeImmutable
    {
        if (null !== $outcome->code && \in_array($outcome->code, $this->finalDeclineCodes, true)) {
            return null;
        }

        $failures = 0;
        $firstAttemptAt = null;
        foreach ($cycle->getAttempts() as $attempt) {
            $firstAttemptAt ??= $attempt->getAttemptedAt();
            if (\in_array($attempt->getOutcome(), [SubscriptionChargeAttemptInterface::OUTCOME_DECLINED, SubscriptionChargeAttemptInterface::OUTCOME_NOT_ATTEMPTED], true)) {
                ++$failures;
            }
        }
        Assert::notNull($firstAttemptAt, 'A retry is only decided after an attempt.');

        if ($failures > \count($this->retryDelays)) {
            return null;
        }

        return $firstAttemptAt->add(new \DateInterval(\sprintf('P%dD', $this->retryDelays[$failures - 1])));
    }
}
