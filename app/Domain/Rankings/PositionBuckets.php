<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Rankings;

final class PositionBuckets
{
    /** Null means checked, not found in the first 100 results.
     * @param list<int|null> $positions
     * @return array<string, int>
     */
    public static function count(array $positions): array
    {
        $counts = ['1–3' => 0, '4–10' => 0, '11–20' => 0, '21+' => 0, 'Not found' => 0];
        foreach ($positions as $position) {
            $bucket = match (true) {
                $position === null || $position < 1 => 'Not found',
                $position <= 3 => '1–3',
                $position <= 10 => '4–10',
                $position <= 20 => '11–20',
                default => '21+',
            };
            ++$counts[$bucket];
        }
        return $counts;
    }
}
