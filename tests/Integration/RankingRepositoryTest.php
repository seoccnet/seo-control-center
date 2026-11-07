<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Keywords\KeywordMetrics;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Rankings\PositionFilter;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\RankingSettings;
use SeoControlCenter\Domain\Rankings\RankingSyncState;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * Rank tracking persistence against MySQL/MariaDB, including the window
 * function query of the keyword table (MySQL 8 / MariaDB 10.6+).
 */
final class RankingRepositoryTest extends FullStackTestCase
{
    public function testSnapshotsAggregatesKeywordTableAndMoves(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($acme['workspace']);
        $ws = $acme['workspace']->id;
        $project = $this->project($ws, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f81');
        $keywords = $this->app->make(KeywordRepository::class);
        $repo = $this->app->make(RankingRepository::class);
        $locale = KeywordLocale::default();
        $keywords->add($project, ['red shoes', 'blue shoes', 'green shoes'], $locale, $acme['user']->id);
        $keywords->storeMetrics($ws, $locale, [new KeywordMetrics('red shoes', 900, null, null, null), new KeywordMetrics('blue shoes', 100, null, null, null)], new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $ids = array_map('intval', array_column($this->db->fetchAll('SELECT id FROM project_keywords WHERE project_id = ? ORDER BY id', [$project]), 'id'));
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $weekAgo = (new \DateTimeImmutable('-7 days', new \DateTimeZone('UTC')))->format('Y-m-d');

        self::assertCount(3, $repo->unchecked($project, SerpDevice::Desktop, $weekAgo, 10));
        $snap = fn(int $i, string $day, ?int $pos): RankSnapshot => new RankSnapshot($ids[$i], $project, SerpDevice::Desktop, $day, $pos, $pos, $pos !== null ? 'https://acme.test/' . $i : null, ['people_also_ask'], [['position' => 1, 'domain' => 'rival.test', 'url' => 'https://rival.test/']]);
        $repo->storeSnapshot($snap(0, $weekAgo, 8));
        $repo->storeSnapshot($snap(1, $weekAgo, 2));
        $repo->storeSnapshot($snap(2, $weekAgo, null));
        $repo->storeSnapshot($snap(0, $today, 5));
        $repo->storeSnapshot($snap(1, $today, 50));
        $repo->storeSnapshot($snap(1, $today, 4)); // re-check of the same day replaces
        self::assertSame(1, $repo->countUnchecked($project, SerpDevice::Desktop, $today));
        self::assertSame([$ids[2]], array_column($repo->unchecked($project, SerpDevice::Desktop, $today, 10), 'id'));
        self::assertSame(3, $repo->countUnchecked($project, SerpDevice::Mobile, $today), 'Devices are checked separately.');
        $repo->storeSnapshot($snap(2, $today, 12));

        $repo->aggregate($project, $ws, SerpDevice::Desktop, $weekAgo);
        $repo->aggregate($project, $ws, SerpDevice::Desktop, $today);
        $latest = $repo->latestDay($project, SerpDevice::Desktop);
        self::assertNotNull($latest);
        self::assertSame($today, $latest->date);
        self::assertSame(3, $latest->ranked);
        self::assertSame(0, $latest->top3);
        self::assertSame(2, $latest->top10);
        self::assertSame(7.0, $latest->averagePosition);
        // (900 * 0.07 + 100 * 0.08 + 1 * 0.01) / (1001 * 0.28)
        self::assertEqualsWithDelta(25.3354, $latest->visibility, 0.0001);
        self::assertSame($weekAgo, $repo->dayOnOrBefore($project, SerpDevice::Desktop, (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d'))?->date);
        self::assertCount(2, $repo->days($project, SerpDevice::Desktop, $weekAgo, $today));
        self::assertNull($repo->latestDay($project, SerpDevice::Mobile));

        $table = $repo->keywords($project, $ws, SerpDevice::Desktop, 10, 0);
        self::assertSame(['blue shoes', 'red shoes', 'green shoes'], array_map(static fn($k): string => $k->keyword, $table));
        self::assertSame(4, $table[0]->position);
        self::assertSame(2, $table[0]->previousPosition);
        self::assertSame(-2, $table[0]->change());
        self::assertSame(2, $table[0]->bestPosition);
        self::assertSame(100, $table[0]->searchVolume);
        self::assertSame(['people_also_ask'], $table[0]->features);
        self::assertSame(3, $table[1]->change());
        self::assertSame(89, $table[2]->change(), 'Entering the results counts from position 101.');

        $moves = $repo->moves($project, SerpDevice::Desktop, $weekAgo, $today);
        self::assertCount(3, $moves);

        $top = $this->db->fetchValue('SELECT top_results FROM ranking_snapshots WHERE project_keyword_id = ? AND checked_on = ?', [$ids[0], $today]);
        self::assertSame('rival.test', json_decode((string) $top, true)[0]['domain']);
    }

    public function testSettingsDueProjectsAndSyncState(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($acme['workspace']);
        $ws = $acme['workspace']->id;
        $daily = $this->project($ws, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f82');
        $weekly = $this->project($ws, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f83');
        $off = $this->project($ws, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f84');
        $deleted = $this->project($ws, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f85');
        $repo = $this->app->make(RankingRepository::class);

        self::assertFalse($repo->settings($daily)->enabled);
        $repo->saveSettings($daily, new RankingSettings(true, SerpDevice::Mobile, RankingSettings::DAILY), $acme['user']->id);
        $repo->saveSettings($weekly, new RankingSettings(true, SerpDevice::Desktop, RankingSettings::WEEKLY), $acme['user']->id);
        $repo->saveSettings($off, RankingSettings::default(), $acme['user']->id);
        $repo->saveSettings($deleted, new RankingSettings(true, SerpDevice::Desktop, RankingSettings::DAILY), $acme['user']->id);
        $this->db->execute('UPDATE projects SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$deleted]);
        self::assertSame(SerpDevice::Mobile, $repo->settings($daily)->device);

        $repo->setSyncState($daily, RankingSyncState::OK, checkedOn: '2026-09-25');
        $repo->setSyncState($weekly, RankingSyncState::OK, checkedOn: '2026-09-22');
        self::assertSame([$daily], array_column($repo->dueProjects('2026-09-26'), 'project_id'));
        self::assertSame([$daily, $weekly], array_column($repo->dueProjects('2026-09-29'), 'project_id'));

        $repo->setSyncState($daily, RankingSyncState::RUNNING);
        $repo->setSyncState($daily, RankingSyncState::ERROR, 'Budget used up');
        $state = $repo->syncState($daily);
        self::assertSame('Budget used up', $state?->lastError);
        self::assertSame('2026-09-25', $state->lastCheckedOn, 'Errors keep the last complete check day.');
        self::assertNotNull($state->lastSuccessAt);

        self::assertSame('acme.test', $repo->projectDomain($daily, $ws));
        self::assertNull($repo->projectDomain($deleted, $ws));
        self::assertNull($repo->projectDomain($daily, $ws + 1000));
    }

    public function testFiltersHistoryKeywordLookupAndSearchConsoleQueryTotals(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($acme['workspace']);
        $ws = $acme['workspace']->id;
        $project = $this->project($ws, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f86');
        $keywords = $this->app->make(KeywordRepository::class);
        $repo = $this->app->make(RankingRepository::class);
        $keywords->add($project, ['red shoes', 'blue shoes', 'green shoes'], KeywordLocale::default(), $acme['user']->id);
        $rows = $this->db->fetchAll('SELECT id, public_id FROM project_keywords WHERE project_id = ? ORDER BY id', [$project]);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $earlier = (new \DateTimeImmutable('-3 days', new \DateTimeZone('UTC')))->format('Y-m-d');
        $snap = fn(int $i, string $day, ?int $pos): RankSnapshot => new RankSnapshot((int) $rows[$i]['id'], $project, SerpDevice::Desktop, $day, $pos, $pos, null, [], [['position' => 1, 'domain' => 'rival.test', 'url' => 'https://rival.test/']]);
        $repo->storeSnapshot($snap(0, $earlier, 1));
        $repo->storeSnapshot($snap(0, $today, 15));
        $repo->storeSnapshot($snap(1, $today, null));

        self::assertSame(3, $repo->countKeywords($project, SerpDevice::Desktop));
        self::assertSame(0, $repo->countKeywords($project, SerpDevice::Desktop, PositionFilter::Top3), 'Only the latest check counts.');
        self::assertSame(1, $repo->countKeywords($project, SerpDevice::Desktop, PositionFilter::Page2));
        self::assertSame(1, $repo->countKeywords($project, SerpDevice::Desktop, PositionFilter::NotRanking), 'Unchecked keywords are not "not ranking".');
        self::assertSame(['red shoes'], array_map(static fn($k): string => $k->keyword, $repo->keywords($project, $ws, SerpDevice::Desktop, 10, 0, PositionFilter::Page2)));

        $history = $repo->history($project, (string) $rows[0]['public_id'], SerpDevice::Desktop, $earlier);
        self::assertSame([1, 15], array_map(static fn($c): ?int => $c->position, $history));
        self::assertSame('rival.test', $history[0]->top[0]['domain']);
        self::assertSame([], $repo->history($project + 1, (string) $rows[0]['public_id'], SerpDevice::Desktop, $earlier), 'Scoped to the project.');

        self::assertSame('blue shoes', $keywords->find($project, $ws, (string) $rows[1]['public_id'])?->keyword);
        self::assertNull($keywords->find($project + 1, $ws, (string) $rows[1]['public_id']));
        self::assertEqualsCanonicalizing(['red shoes', 'blue shoes', 'green shoes'], $keywords->texts($project));

        $store = $this->app->make(SearchPerformanceStore::class);
        $store->upsertDailyTotals($project, [new DailyMetrics('2026-09-20', new Metrics(9, 300, 5.0))]);
        $store->replaceDay($project, '2026-09-20', ['query' => [new DimensionRow('red shoes', new Metrics(4, 120, 3.5))], 'page' => [], 'device' => [], 'country' => []]);
        $reader = $this->app->make(SearchPerformanceReader::class);
        $range = DateRange::endingOn('2026-09-20', 28);
        $totals = $reader->queryTotals($project, 'red shoes', $range);
        self::assertSame(4, $totals?->clicks);
        self::assertSame(120, $totals->impressions);
        self::assertNull($reader->queryTotals($project, 'blue shoes', $range));
    }

    public function testOverviewBucketsUseExactDaysAndSharedCheckedCohort(): void
    {
        $acme = $this->createUser('buckets@acme.test', 'Buckets');
        self::assertNotNull($acme['workspace']);
        $ws = $acme['workspace']->id;
        $project = $this->project($ws, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f93');
        $keywords = $this->app->make(KeywordRepository::class);
        $keywords->add($project, ['a', 'b', 'c', 'unchecked'], KeywordLocale::default(), $acme['user']->id);
        $ids = array_map('intval', array_column($this->db->fetchAll('SELECT id FROM project_keywords WHERE project_id = ? ORDER BY id', [$project]), 'id'));
        $repo = $this->app->make(RankingRepository::class);
        foreach ([['2026-09-29', 0, 1], ['2026-09-29', 1, null], ['2026-10-06', 0, 21], ['2026-10-06', 1, 10], ['2026-10-06', 2, 3]] as [$date, $i, $position]) {
            $repo->storeSnapshot(new RankSnapshot($ids[$i], $project, SerpDevice::Desktop, $date, $position, $position, null, [], []));
        }
        self::assertSame(['1–3' => 1, '4–10' => 1, '11–20' => 0, '21+' => 1, 'Not found' => 0], $repo->positionBuckets($project, $ws, SerpDevice::Desktop, '2026-10-06'));
        $common = $repo->positionBuckets($project, $ws, SerpDevice::Desktop, '2026-10-06', '2026-09-29');
        self::assertSame(2, array_sum($common));
        self::assertSame(0, $common['1–3']);
        self::assertSame(1, $repo->positionBuckets($project, $ws, SerpDevice::Desktop, '2026-09-29', '2026-10-06')['Not found']);
        self::assertSame(0, array_sum($repo->positionBuckets($project, $ws + 999, SerpDevice::Desktop, '2026-10-06')));
        self::assertSame(0, array_sum($repo->positionBuckets($project, $ws, SerpDevice::Mobile, '2026-10-06')));
    }

    private function project(int $workspaceId, string $publicId): int
    {
        $this->db->execute(
            "INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at)
             VALUES (?, ?, 'Shop', 'acme.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$publicId, $workspaceId],
        );

        return $this->db->lastInsertId();
    }
}
