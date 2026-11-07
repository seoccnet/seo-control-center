<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\ArticleIdeas;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdea;
use SeoControlCenter\Domain\ArticleIdeas\ArticleTracking;

final class ArticleTrackingTest extends TestCase
{
    public function testWeeksTopTenAndRecentClicksAreMeasured(): void
    {
        $row = static fn(string $q, string $week, int $c, int $i, float $p): array => ['query' => $q, 'url' => 'https://shop.test/blog/mold', 'week' => $week, 'clicks' => $c, 'impressions' => $i, 'position' => $p];
        $result = ArticleTracking::measure([
            $row('mold on pepper', '2026-08-03', 0, 40, 34.0),
            $row('mold on pepper', '2026-08-31', 2, 5, 8.0),
            $row('mold on pepper', '2026-09-07', 9, 60, 9.4),
            $row('moldy blossoms', '2026-09-07', 1, 20, 15.0),
            $row('mold on pepper', '2026-10-05', 30, 120, 6.0),
            $row('moldy blossoms', '2026-10-12', 4, 30, 7.0),
        ], 'mold on pepper', '2026-10-18');

        self::assertSame('2026-09-07', $result['top10_week'], 'The first top-10 week with at least 10 impressions; 5 impressions at position 8 do not count.');
        self::assertSame(['week' => '2026-09-07', 'clicks' => 10, 'impressions' => 80, 'position' => 10.8], $result['weeks'][2], 'Impression-weighted over the searches.');
        self::assertSame(6.0, $result['best_position']);
        self::assertSame(34, $result['clicks_28d'], 'Weeks from 2026-09-20 on.');

        $tracking = self::tracking('2026-07-30', $result);
        self::assertSame(1.3, $tracking->monthsToTop10());
        self::assertSame(37, $tracking->monthlyClicks());
        self::assertSame(2.6, $tracking->monthsSince('2026-10-18'));
        self::assertNull(self::tracking('2026-07-30', null)->monthsToTop10());
    }

    public function testCalibrationNeedsThreeArticlesPerKindAndLevel(): void
    {
        $reached = static fn(string $published, string $top10, string $level = 'low', string $kind = ArticleIdea::NEW): ArticleTracking => self::tracking($published, ['weeks' => [], 'top10_week' => $top10, 'best_position' => 5.0, 'clicks_28d' => 0], $level, $kind);
        $trackings = [
            $reached('2026-01-01', '2026-02-15'),
            $reached('2026-01-01', '2026-04-01'),
            $reached('2026-01-01', '2026-06-01'),
            $reached('2026-01-01', '2026-03-01', 'medium'),
            $reached('2026-01-01', '2026-03-01', 'low', ArticleIdea::UPDATE),
            self::tracking('2026-01-01', null),
        ];

        self::assertSame(['new:low' => ['low' => 2, 'high' => 4, 'count' => 3]], ArticleTracking::calibration($trackings), '1.5, 3.0 and 5.0 months: 25th–75th percentile 2.2–4.0.');
    }

    /** @param array{weeks: list<array{week: string, clicks: int, impressions: int, position: ?float}>, top10_week: ?string, best_position: ?float, clicks_28d: int}|null $result */
    private static function tracking(string $published, ?array $result, string $level = 'low', string $kind = ArticleIdea::NEW): ArticleTracking
    {
        return new ArticleTracking(1, 'x', 1, 1, str_repeat('a', 64), 'mold on pepper', ['mold on pepper'], $kind, $level, [
            'traffic' => ['low' => 100, 'expected' => 400, 'high' => 1000],
            'months' => ['low' => 1, 'high' => 3, 'basis' => 'An assumption.'],
        ], 'https://shop.test/blog/mold', $published, ArticleTracking::MANUAL, $result, null, '2026-07-30 10:00:00');
    }
}
