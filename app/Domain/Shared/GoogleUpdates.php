<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Shared;

/**
 * Google's confirmed ranking updates (1.5.0), as listed on the Google Search
 * Status Dashboard (status.search.google.com, "Ranking"). The list ships with
 * each release, so SEOcc never calls Google for it; updates announced after
 * a release appear with the next one. End dates are the start plus the
 * duration Google reports, so they can be a day off. An update without an
 * end date is still rolling out.
 */
final class GoogleUpdates
{
    /** @var list<array{0: string, 1: string, 2: ?string}> name, start, end */
    private const array UPDATES = [
        ['February 2023 product reviews update', '2023-02-21', '2023-03-07'],
        ['March 2023 core update', '2023-03-15', '2023-03-28'],
        ['April 2023 reviews update', '2023-04-12', '2023-04-25'],
        ['August 2023 core update', '2023-08-22', '2023-09-07'],
        ['September 2023 helpful content update', '2023-09-14', '2023-09-28'],
        ['October 2023 spam update', '2023-10-04', '2023-10-19'],
        ['October 2023 core update', '2023-10-05', '2023-10-19'],
        ['November 2023 core update', '2023-11-02', '2023-11-27'],
        ['November 2023 reviews update', '2023-11-08', '2023-12-07'],
        ['March 2024 core update', '2024-03-05', '2024-04-19'],
        ['March 2024 spam update', '2024-03-05', '2024-03-20'],
        ['June 2024 spam update', '2024-06-20', '2024-06-27'],
        ['August 2024 core update', '2024-08-15', '2024-09-03'],
        ['November 2024 core update', '2024-11-11', '2024-12-05'],
        ['December 2024 core update', '2024-12-12', '2024-12-18'],
        ['December 2024 spam update', '2024-12-19', '2024-12-26'],
        ['March 2025 core update', '2025-03-13', '2025-03-27'],
        ['June 2025 core update', '2025-06-30', '2025-07-17'],
        ['August 2025 spam update', '2025-08-26', '2025-09-22'],
        ['December 2025 core update', '2025-12-11', '2025-12-29'],
        ['February 2026 Discover update', '2026-02-05', '2026-02-27'],
        ['March 2026 spam update', '2026-03-24', '2026-03-25'],
        ['March 2026 core update', '2026-03-27', '2026-04-08'],
        ['May 2026 core update', '2026-05-21', '2026-06-02'],
        ['June 2026 spam update', '2026-06-24', '2026-06-26'],
        ['August 2026 spam update', '2026-08-18', '2026-08-21'],
        ['September 2026 spam update', '2026-09-24', '2026-10-08'],
        ['November 2026 core update', '2026-11-10', null],
    ];

    /**
     * Updates that overlap the period, oldest first. A rollout without an
     * end date counts as running until the end of the period.
     *
     * @return list<array{name: string, start: string, end: ?string}>
     */
    public static function between(string $from, string $to): array
    {
        $result = [];
        foreach (self::UPDATES as [$name, $start, $end]) {
            if ($start <= $to && ($end ?? $to) >= $from) {
                $result[] = ['name' => $name, 'start' => $start, 'end' => $end];
            }
        }

        return $result;
    }

    /** @return list<array{name: string, start: string, end: ?string}> */
    public static function all(): array
    {
        return self::between('0000-00-00', '9999-12-31');
    }
}
