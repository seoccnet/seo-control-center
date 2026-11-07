<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Opportunities\TrendReader;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Domain\Shared\DateRange;

final class TrendReaderTest extends FullStackTestCase
{
    public function testThreePeriodsImpressionCapSeasonalityMarksAndProjectIsolation(): void
    {
        $owner = $this->createUser('trends@acme.test', 'Trends');
        self::assertNotNull($owner['workspace']);
        $project = $this->project($owner['workspace']->id, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fd1');
        $foreign = $this->project($owner['workspace']->id, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fd2');
        $store = $this->app->make(SearchPerformanceStore::class);
        $queries = [new DimensionRow('largest demand', new Metrics(0, 10000, 8))];
        for ($i = 0; $i < 500; ++$i) {
            $queries[] = new DimensionRow('topic ' . $i, new Metrics(100, 1000, 8));
        }
        foreach (['2026-08-09' => 150, '2026-09-06' => 100, '2026-10-06' => 50] as $date => $clicks) {
            $store->replaceDay($project, $date, ['query' => $queries, 'page' => [new DimensionRow('https://acme.test/topic', new Metrics($clicks, 1000, 8))], 'device' => [], 'country' => []]);
        }
        $store->replaceDay($foreign, '2026-10-06', ['query' => [new DimensionRow('foreign-only', new Metrics(9999, 99999, 1))], 'page' => [], 'device' => [], 'country' => []]);
        $page = (int) $this->db->fetchValue('SELECT id FROM pages WHERE project_id = ? AND url = ?', [$project, 'https://acme.test/topic']);
        $last = new \DateTimeImmutable('2026-09-01');
        for ($i = 0; $i < 14; ++$i) {
            $this->db->execute('INSERT INTO search_console_rollup_months (project_id, month, refreshed_at) VALUES (?, ?, ?)', [$project, $last->modify('-' . $i . ' months')->format('Y-m-d'), '2026-10-08 00:00:00']);
        }
        foreach (['2025-08-01' => 1000, '2025-09-01' => 500] as $month => $clicks) {
            $this->db->execute('INSERT INTO search_console_pages_monthly (project_id, month, page_id, clicks, impressions, position_sum) VALUES (?, ?, ?, ?, ?, ?)', [$project, $month, $page, $clicks, 10000, 80000]);
        }
        $reader = $this->app->make(TrendReader::class);
        $range = new DateRange('2026-09-09', '2026-10-06');
        $snapshot = $reader->snapshot($project, $range);
        self::assertSame(501, $snapshot->queryCount);
        self::assertSame('Shop', $snapshot->projectName);
        self::assertCount(500, $snapshot->queries);
        self::assertSame('largest demand', $snapshot->queries[0]->key, 'Select by impressions rather than current clicks.');
        self::assertSame(50, $snapshot->pages[0]->metrics->clicks);
        self::assertSame(100, $snapshot->previousPages['https://acme.test/topic']->clicks);
        self::assertSame(10000, $snapshot->previousQueries['largest demand']->impressions, 'Queries carry the preceding window too.');
        self::assertSame(100, $snapshot->previousQueries['topic 0']->clicks);
        self::assertSame(150, $snapshot->olderPages['https://acme.test/topic']->clicks);
        self::assertSame(.5, $snapshot->seasonalRatios['https://acme.test/topic']);
        self::assertNotContains('foreign-only', array_column($snapshot->queries, 'key'));
        $this->db->execute('DELETE FROM search_console_rollup_months WHERE project_id = ? AND month = ?', [$project, '2026-05-01']);
        self::assertSame([], $reader->snapshot($project, $range)->seasonalRatios, 'Missing monthly mark invalidates the complete seasonal context.');
        self::assertSame([], $reader->snapshot($foreign, $range)->seasonalRatios);
        self::assertSame(1, $reader->snapshot($foreign, $range)->queryCount);
    }

    private function project(int $workspace, string $publicId): int
    {
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'acme.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$publicId, $workspace]);
        return $this->db->lastInsertId();
    }
}
