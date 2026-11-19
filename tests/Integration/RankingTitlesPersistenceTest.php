<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * The 1.7.0 queries on the real engine: shown titles, ranking volatility
 * (window functions) and comparisons with a year earlier.
 */
final class RankingTitlesPersistenceTest extends FullStackTestCase
{
    private int $workspaceId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = $this->createUser('reports@acme.test', 'Reports');
        self::assertNotNull($owner['workspace']);
        $this->workspaceId = $owner['workspace']->id;
        $this->userId = $owner['user']->id;
    }

    public function testShownTitlesAndVolatility(): void
    {
        $project = $this->project('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f91');
        $other = $this->project('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f92');
        $keywords = $this->app->make(KeywordRepository::class);
        $repo = $this->app->make(RankingRepository::class);
        $keywords->add($project, ['k0', 'k1', 'k2', 'k3', 'k4', 'k5'], KeywordLocale::default(), $this->userId);
        $keywords->add($other, ['k0'], KeywordLocale::default(), $this->userId);
        $ids = array_map('intval', array_column($this->db->fetchAll('SELECT id FROM project_keywords WHERE project_id = ? ORDER BY id', [$project]), 'id'));
        $otherId = (int) $this->db->fetchValue('SELECT id FROM project_keywords WHERE project_id = ?', [$other]);

        $snap = static fn(int $id, int $p, string $day, ?int $pos, ?string $title = null, SerpDevice $device = SerpDevice::Desktop): RankSnapshot => new RankSnapshot($id, $p, $device, $day, $pos, $pos, $pos !== null ? 'https://acme.test/' . $id : null, [], [], shownTitle: $title);
        $repo->storeSnapshot($snap($ids[0], $project, '2026-11-01', 3, 'Old title'));
        $repo->storeSnapshot($snap($ids[0], $project, '2026-11-02', 3, 'New title ü'));
        $repo->storeSnapshot($snap($ids[1], $project, '2026-11-02', null));
        $repo->storeSnapshot($snap($ids[2], $project, '2026-10-01', 4, 'Before since'));
        $repo->storeSnapshot($snap($ids[3], $project, '2026-11-02', 4, 'Mobile', SerpDevice::Mobile));
        $repo->storeSnapshot($snap($otherId, $other, '2026-11-02', 1, 'Other project'));
        self::assertSame([['url' => 'https://acme.test/' . $ids[0], 'title' => 'New title ü', 'keyword' => 'k0', 'checkedOn' => '2026-11-02']], $repo->shownTitles($project, SerpDevice::Desktop, '2026-10-15', 100));
        $repo->storeSnapshot($snap($ids[0], $project, '2026-11-02', 3, null));
        self::assertSame('Old title', $repo->shownTitles($project, SerpDevice::Desktop, '2026-10-15', 100)[0]['title'] ?? null, 'A re-check without a title clears it; the latest one with a title counts.');

        // Six keywords on two consecutive checks, and one check ten days later.
        $this->db->execute('DELETE FROM ranking_snapshots WHERE project_id = ?', [$project]);
        $positions = [[1, 2], [3, 3], [5, 9], [null, 30], [25, 40], [8, null]];
        foreach ($positions as $i => [$before, $after]) {
            $repo->storeSnapshot($snap($ids[$i], $project, '2026-11-10', $before));
            $repo->storeSnapshot($snap($ids[$i], $project, '2026-11-11', $after));
        }
        $repo->storeSnapshot($snap($ids[0], $project, '2026-11-21', 9));
        $repo->storeSnapshot($snap($ids[1], $project, '2026-11-12', 50));
        $this->db->execute("UPDATE ranking_snapshots SET source = 'import' WHERE project_keyword_id = ? AND checked_on = '2026-11-12'", [$ids[1]]);
        // Moves 1, 0, 4 and 13; pairs outside the top 20 on both checks are left out.
        self::assertSame([['date' => '2026-11-11', 'pairs' => 4, 'moved' => 3, 'average' => 4.5]], $repo->volatility($project, SerpDevice::Desktop, '2026-11-01'));
        self::assertSame([], $repo->volatility($project, SerpDevice::Desktop, '2026-11-12'), 'Too far apart, or imported.');
        self::assertSame([], $repo->volatility($project, SerpDevice::Mobile, '2026-11-01'));
    }

    public function testComparedWithTheSameWeeksAYearEarlier(): void
    {
        $project = $this->project('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f93');
        $store = $this->app->make(SearchPerformanceStore::class);
        $reader = $this->app->make(SearchPerformanceReader::class);
        $days = ['2025-10-20' => 2, '2025-11-16' => 3, '2026-10-01' => 50, '2026-10-19' => 7, '2026-11-15' => 1];
        $store->upsertDailyTotals($project, array_map(static fn(string $d): DailyMetrics => new DailyMetrics($d, new Metrics(1, 10, 3.0)), array_keys($days)));
        foreach ($days as $day => $clicks) {
            $store->replaceDay($project, $day, [
                'query' => [new DimensionRow('coats', new Metrics($clicks, 100, 4.0))],
                'page' => [new DimensionRow('https://acme.test/coats', new Metrics($clicks, 100, 4.0))],
                'device' => [],
                'country' => [],
            ]);
        }
        $range = DateRange::endingOn('2026-11-15', 28);
        $year = $reader->compared($project, PerformanceDimension::Query, $range, 10, $range->yearEarlier());
        self::assertSame(1, $year['total']);
        self::assertSame(8, $year['rows'][0]['current']->clicks);
        self::assertSame(5, $year['rows'][0]['previous']->clicks, 'Clicks between the two periods are not counted.');
        self::assertSame(50, $reader->compared($project, PerformanceDimension::Page, $range, 10)['rows'][0]['previous']->clicks, 'Without an earlier period, the period directly before.');
        $this->expectException(\InvalidArgumentException::class);
        $reader->compared($project, PerformanceDimension::Query, $range, 10, DateRange::endingOn('2026-10-20', 7));
    }

    private function project(string $publicId): int
    {
        $this->db->execute(
            "INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at)
             VALUES (?, ?, 'Shop', 'acme.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$publicId, $this->workspaceId],
        );

        return $this->db->lastInsertId();
    }
}
