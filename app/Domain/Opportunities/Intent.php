<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

final class Intent
{
    /** Conservative English patterns; unsupported/ambiguous queries are neutral.
     * @return array{label: string, weight: float}
     */
    public static function of(string $query): array
    {
        $query = mb_strtolower($query);
        return match (true) {
            preg_match('/\b(login|log in|sign in|customer portal)\b/u', $query) === 1 => ['label' => 'navigational', 'weight' => 0.75],
            preg_match('/\b(buy|order|book|purchase|for sale|coupon)\b/u', $query) === 1 => ['label' => 'transactional', 'weight' => 1.25],
            preg_match('/\b(best|review|reviews|compare|comparison|versus|pricing|price)\b/u', $query) === 1 => ['label' => 'commercial', 'weight' => 1.1],
            preg_match('/\b(how|what|why|guide|tutorial|definition)\b/u', $query) === 1 => ['label' => 'informational', 'weight' => 0.9],
            default => ['label' => 'unknown', 'weight' => 1.0],
        };
    }
}
