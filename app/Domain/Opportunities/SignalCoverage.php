<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

/** Coverage is evidence of a completed local snapshot, never of provider availability. */
final class SignalCoverage
{
    /** @return array{status: string, date: ?string, notice?: string} */
    public static function at(?string $date, \DateTimeImmutable $now, int $maxAgeDays): array
    {
        if ($date === null) {
            return ['status' => 'missing', 'date' => null];
        }
        $date = substr($date, 0, 10);
        return ['status' => $date >= $now->modify('-' . $maxAgeDays . ' days')->format('Y-m-d') && $date <= $now->format('Y-m-d') ? 'available' : 'stale', 'date' => $date];
    }

    /** @param array<string, array{status: string, date: ?string, notice?: string}> $coverage
     * @return array<string, array{status: string, date: ?string, notice?: string}>
     */
    public static function summary(array $coverage): array
    {
        return array_filter($coverage, static fn(string $key): bool => !str_contains($key, ':') && !str_ends_with($key, '_scope'), ARRAY_FILTER_USE_KEY);
    }

    /** @param array<string, array{status: string, date: ?string, notice?: string}> $coverage
     * @param list<string> $sources
     */
    public static function complete(array $coverage, array $sources): bool
    {
        foreach ($sources as $source) {
            if (($coverage[$source]['status'] ?? 'missing') !== 'available') {
                return false;
            }
        }
        return true;
    }
}
