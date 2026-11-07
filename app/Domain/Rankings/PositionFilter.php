<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Rankings;

/**
 * Position buckets for filtering the rankings table (latest check).
 */
enum PositionFilter: string
{
    case Top3 = 'top3';
    case Top10 = 'top10';
    case Page2 = '11-20';
    case Deep = '21-100';
    case NotRanking = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Top3 => 'Top 3',
            self::Top10 => 'Top 10',
            self::Page2 => '11–20',
            self::Deep => '21–100',
            self::NotRanking => 'Not in top 100',
        };
    }

    public function matches(?int $position): bool
    {
        return match ($this) {
            self::Top3 => $position !== null && $position <= 3,
            self::Top10 => $position !== null && $position <= 10,
            self::Page2 => $position !== null && $position >= 11 && $position <= 20,
            self::Deep => $position !== null && $position >= 21,
            self::NotRanking => $position === null,
        };
    }
}
