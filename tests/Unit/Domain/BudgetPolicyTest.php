<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Integrations\ApiBudget;
use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Integrations\BudgetPolicy;
use SeoControlCenter\Domain\Integrations\UsageSummary;

final class BudgetPolicyTest extends TestCase
{
    public function testUnlimitedBudgetAllowsAnything(): void
    {
        BudgetPolicy::check(ApiBudget::unlimited(), new UsageSummary(1000.0, 5000.0, 10, 50), 99.0);
        $this->expectNotToPerformAssertions();
    }

    public function testRequestExactlyReachingTheDailyLimitIsAllowed(): void
    {
        BudgetPolicy::check(new ApiBudget(dailyLimit: 1.0), new UsageSummary(0.9, 0.9, 1, 1), 0.1);
        $this->expectNotToPerformAssertions();
    }

    public function testDailyLimitIsEnforced(): void
    {
        $this->expectException(BudgetExceededException::class);
        $this->expectExceptionMessage('daily budget of $1');
        BudgetPolicy::check(new ApiBudget(dailyLimit: 1.0), new UsageSummary(0.95, 0.95, 3, 3), 0.1);
    }

    public function testMonthlyLimitIsEnforced(): void
    {
        $this->expectException(BudgetExceededException::class);
        $this->expectExceptionMessage('monthly budget of $20');
        BudgetPolicy::check(new ApiBudget(dailyLimit: 5.0, monthlyLimit: 20.0), new UsageSummary(0.0, 19.99, 0, 40), 0.05);
    }

    public function testPerOperationLimitIsEnforcedEvenWithoutSpending(): void
    {
        $this->expectException(BudgetExceededException::class);
        $this->expectExceptionMessage('limit of $0.5 per request');
        BudgetPolicy::check(new ApiBudget(operationLimit: 0.5), UsageSummary::none(), 0.75);
    }

    public function testNegativeEstimateIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BudgetPolicy::check(ApiBudget::unlimited(), UsageSummary::none(), -1.0);
    }

    public function testNonFiniteEstimatesCannotBypassLimits(): void
    {
        foreach ([NAN, INF, -INF] as $estimate) {
            try {
                BudgetPolicy::check(new ApiBudget(dailyLimit: 1.0), UsageSummary::none(), $estimate);
                self::fail('Non-finite estimates must be rejected.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('finite', $e->getMessage());
            }
        }
    }

    public function testMoneyFormatting(): void
    {
        self::assertSame('0', BudgetPolicy::money(0.0));
        self::assertSame('2.5', BudgetPolicy::money(2.5));
        self::assertSame('0.0006', BudgetPolicy::money(0.0006));
        self::assertSame('12', BudgetPolicy::money(12.0));
    }
}
