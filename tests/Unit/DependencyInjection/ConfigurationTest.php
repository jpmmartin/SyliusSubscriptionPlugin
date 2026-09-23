<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Unit\DependencyInjection;

use JpmMartin\SyliusSubscriptionPlugin\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testItDefaultsToNoPaymentMethodsThreeRetriesNoFinalCodeAndSuspensionAfterThreeFailedCyclesInARow(): void
    {
        $config = $this->process([]);

        self::assertSame([], $config['payment_methods']);
        self::assertSame([1, 3, 7], $config['retry_delays']);
        self::assertSame([], $config['final_decline_codes']);
        self::assertSame(3, $config['suspend_after_failed_cycles']);
        self::assertSame('1', $config['consent_version']);
        self::assertArrayNotHasKey('on_failure', $config);
    }

    public function testItAcceptsAStoreConfiguration(): void
    {
        $config = $this->process([
            'payment_methods' => ['card_on_file'],
            'retry_delays' => [2, 5],
            'final_decline_codes' => ['stolen_card', 'lost_card'],
            'suspend_after_failed_cycles' => 5,
            'consent_version' => '2026-09',
        ]);

        self::assertSame(['card_on_file'], $config['payment_methods']);
        self::assertSame([2, 5], $config['retry_delays']);
        self::assertSame(['stolen_card', 'lost_card'], $config['final_decline_codes']);
        self::assertSame(5, $config['suspend_after_failed_cycles']);
        self::assertSame('2026-09', $config['consent_version']);
    }

    public function testAStoreCanChooseNeverToRetry(): void
    {
        self::assertSame([], $this->process(['retry_delays' => []])['retry_delays']);
    }

    public function testItRejectsAnEmptyFinalDeclineCode(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['final_decline_codes' => ['stolen_card', '']]);
    }

    public function testAStoreCanChooseToNeverSuspend(): void
    {
        self::assertNull($this->process(['suspend_after_failed_cycles' => null])['suspend_after_failed_cycles']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidFailureThresholds(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-2];
        yield 'not a number' => ['three'];
    }

    #[DataProvider('invalidFailureThresholds')]
    public function testItRejectsAFailureThresholdThatIsNotAPositiveInteger(mixed $threshold): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('positive integer');

        $this->process(['suspend_after_failed_cycles' => $threshold]);
    }

    public function testItRejectsRetryDelaysThatAreNotStrictlyIncreasing(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('strictly increasing');

        $this->process(['retry_delays' => [3, 3]]);
    }

    public function testItRejectsARetryDelayBelowOneDay(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['retry_delays' => [0, 2]]);
    }

    public function testTheRemovedFailurePolicyPointsToItsReplacement(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('suspend_after_failed_cycles');

        $this->process(['on_failure' => 'cancel']);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
