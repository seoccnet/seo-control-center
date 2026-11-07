<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Integrations;

/**
 * Decides whether a paid request with an estimated cost fits the budget.
 * Shared by every ledger implementation so the rule is tested once.
 */
final class BudgetPolicy
{
    /** Tolerance for DECIMAL/float rounding. */
    private const float EPSILON = 0.000001;

    /**
     * @throws BudgetExceededException
     */
    public static function check(ApiBudget $budget, UsageSummary $spent, float $estimatedCost): void
    {
        if (!is_finite($estimatedCost) || $estimatedCost < 0) {
            throw new \InvalidArgumentException('Estimated cost must be finite and non-negative.');
        }
        if ($budget->operationLimit !== null && $estimatedCost > $budget->operationLimit + self::EPSILON) {
            throw new BudgetExceededException(sprintf(
                'This request would cost about $%s, more than the limit of $%s per request.',
                self::money($estimatedCost),
                self::money($budget->operationLimit),
            ));
        }
        if ($budget->dailyLimit !== null && $spent->today + $estimatedCost > $budget->dailyLimit + self::EPSILON) {
            throw new BudgetExceededException(sprintf(
                'The daily budget of $%s is used up ($%s spent today, UTC).',
                self::money($budget->dailyLimit),
                self::money($spent->today),
            ));
        }
        if ($budget->monthlyLimit !== null && $spent->month + $estimatedCost > $budget->monthlyLimit + self::EPSILON) {
            throw new BudgetExceededException(sprintf(
                'The monthly budget of $%s is used up ($%s spent this month, UTC).',
                self::money($budget->monthlyLimit),
                self::money($spent->month),
            ));
        }
    }

    public static function money(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 4, '.', ''), '0'), '.') ?: '0';
    }
}
