<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Unit\OrderProcessing;

use JpmMartin\SyliusSubscriptionPlugin\OrderProcessing\SubscriptionPlanPriceProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubscriptionPlanPriceProcessorTest extends TestCase
{
    #[DataProvider('discounts')]
    public function testTheDiscountIsTakenOffAndRoundedToTheMinorUnit(int $price, int $discountPercentage, int $expected): void
    {
        self::assertSame($expected, SubscriptionPlanPriceProcessor::applyDiscount($price, $discountPercentage));
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function discounts(): iterable
    {
        yield 'ten percent off 100.00' => [10000, 10, 9000];
        yield 'no discount' => [10000, 0, 10000];
        yield 'free' => [10000, 100, 0];
        yield 'rounds down below half' => [1999, 10, 1799];
        yield 'rounds half up' => [1995, 10, 1796];
    }
}
