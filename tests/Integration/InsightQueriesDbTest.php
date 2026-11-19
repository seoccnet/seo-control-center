<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Competitors\CompetitorRepository;
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
use SeoControlCenter\Modules\Backlinks\OutreachRepository;
use SeoControlCenter\Modules\Rankings\KeywordTargetRepository;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;

/**
 * The 1.8.0 queries and tables on the real engine: competitor visibility
 * history, ranking URLs, keyword targets, audit links, Search Console
 * periods and the outreach list.
 */
final class InsightQueriesDbTest extends FullStackTestCase
{
    private int $workspaceId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = $this->createUser('insights@acme.test', 'Insights');
        self::assertNotNull($owner['workspace']);
        $this->workspaceId = $owner['workspace']->id;
        $this->userId = $owner['user']->id;
    }

    public function testRankingsCompetitorsAndTargets(): void
    {
        $project = $this->project('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fa1');
        $other = $this->project('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fa2');
        $competitors = $this->app->make(CompetitorRepository::class);
        $rival = $competitors->add($project, 'rival.test', 'manual', $this->userId) ?? self::fail('Not added.');
        $foreign = $competitors->add($other, 'rival.test', 'manual', $this->userId) ?? self::fail('Not added.');
        foreach ([[$rival->id, '2026-10-01', 12.5], [$rival->id, '2026-11-01', 20.25], [$rival->id, '2026-12-01', 1.0], [$foreign->id, '2026-11-01', 99.0]] as [$id, $date, $visibility]) {
            $this->db->execute("INSERT INTO competitor_daily (competitor_id, device, date, keywords, ranked, top3, top10, average_position, visibility) VALUES (?, 'desktop', ?, 10, 5, 1, 3, 7.5, ?)", [$id, $date, $visibility]);
        }
        self::assertSame(['2026-10-01' => [$rival->id => 12.5], '2026-11-01' => [$rival->id => 20.25]], $competitors->visibilityHistory($project, SerpDevice::Desktop, '2026-09-01', '2026-11-15'));
        self::assertSame([], $competitors->visibilityHistory($project, SerpDevice::Mobile, '2026-09-01', '2026-11-15'));

        $keywords = $this->app->make(KeywordRepository::class);
        $rankings = $this->app->make(RankingRepository::class);
        $keywords->add($project, ['red shoes', 'blue boots'], KeywordLocale::default(), $this->userId);
        $keywords->add($other, ['red shoes'], KeywordLocale::default(), $this->userId);
        $rows = $this->db->fetchAll('SELECT id, public_id, keyword FROM project_keywords WHERE project_id = ? ORDER BY id', [$project]);
        $ids = array_column($rows, 'id', 'keyword');
        $public = array_column($rows, 'public_id', 'keyword');
        $foreignKeyword = (string) $this->db->fetchValue('SELECT public_id FROM project_keywords WHERE project_id = ?', [$other]);
        $snap = static fn(string $k, string $date, ?int $pos, ?string $url): RankSnapshot => new RankSnapshot((int) $ids[$k], $project, SerpDevice::Desktop, $date, $pos, $pos, $url, [], []);
        $rankings->storeSnapshot($snap('red shoes', '2026-10-01', 4, 'https://acme.test/red'));
        $rankings->storeSnapshot($snap('red shoes', '2026-10-08', 5, 'https://acme.test/shoes'));
        $rankings->storeSnapshot($snap('red shoes', '2026-08-01', 5, 'https://acme.test/old'));
        $rankings->storeSnapshot($snap('blue boots', '2026-10-08', null, null));
        $history = $rankings->urlHistory($project, $this->workspaceId, SerpDevice::Desktop, '2026-09-01');
        self::assertSame([(string) $public['red shoes'], (string) $public['blue boots']], array_keys($history));
        self::assertSame([
            ['date' => '2026-10-01', 'position' => 4, 'url' => 'https://acme.test/red'],
            ['date' => '2026-10-08', 'position' => 5, 'url' => 'https://acme.test/shoes'],
        ], $history[(string) $public['red shoes']]['checks']);
        self::assertSame(['keyword' => 'blue boots', 'volume' => null, 'checks' => [['date' => '2026-10-08', 'position' => null, 'url' => null]]], $history[(string) $public['blue boots']]);

        $targets = $this->app->make(KeywordTargetRepository::class);
        self::assertSame(2, $targets->set($project, [(string) $public['red shoes'] => 'https://acme.test/red', (string) $public['blue boots'] => 'https://acme.test/boots', $foreignKeyword => 'https://acme.test/x'], $this->userId));
        self::assertSame(1, $targets->set($project, [(string) $public['red shoes'] => 'https://acme.test/red-shoes'], $this->userId), 'Setting again replaces.');
        self::assertSame(1, $targets->set($project, [(string) $public['blue boots'] => null], $this->userId));
        self::assertSame([(string) $public['red shoes'] => 'https://acme.test/red-shoes'], $targets->targets($project));
        self::assertSame([], $targets->targets($other), 'A keyword of another project is skipped.');
        $this->db->execute('DELETE FROM project_keywords WHERE id = ?', [(int) $ids['red shoes']]);
        self::assertSame([], $targets->targets($project), 'Removed with the keyword.');
    }

    public function testAuditLinksSearchPeriodsAndOutreach(): void
    {
        $project = $this->project('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fa3');
        $this->db->execute("INSERT INTO site_audits (public_id, project_id, workspace_id, status, trigger_type, start_url, max_pages, created_at, updated_at, finished_at) VALUES ('0192f0a0-0000-7000-8000-000000000e01', ?, ?, 'completed', 'manual', 'https://acme.test/', 100, '2026-10-20 08:00:00', '2026-10-20 10:00:00', '2026-10-20 10:00:00')", [$project, $this->workspaceId]);
        $audit = $this->db->lastInsertId();
        $pages = [];
        foreach (['/', '/a', '/b'] as $path) {
            $url = 'https://acme.test' . $path;
            $this->db->execute("INSERT INTO site_audit_pages (audit_id, url, url_hash, depth, state, status_code, crawled_at) VALUES (?, ?, ?, 1, 'done', 200, '2026-10-20 09:00:00')", [$audit, $url, sha1($url)]);
            $pages[$path] = $this->db->lastInsertId();
        }
        foreach ([['/', '/a'], ['/', '/b'], ['/a', '/b']] as [$from, $to]) {
            $this->db->execute('INSERT INTO site_audit_links (audit_id, from_page_id, to_page_id) VALUES (?, ?, ?)', [$audit, $pages[$from], $pages[$to]]);
        }
        $links = $this->app->make(SiteAuditRepository::class)->links($audit);
        sort($links);
        self::assertSame([[$pages['/'], $pages['/a']], [$pages['/'], $pages['/b']], [$pages['/a'], $pages['/b']]], $links);

        $store = $this->app->make(SearchPerformanceStore::class);
        $reader = $this->app->make(SearchPerformanceReader::class);
        $days = ['2026-01-10' => [8, 3], '2026-04-10' => [6, 0], '2026-07-10' => [4, 5], '2026-10-10' => [2, 1]];
        $store->upsertDailyTotals($project, array_map(static fn(string $d): DailyMetrics => new DailyMetrics($d, new Metrics(1, 10, 3.0)), array_keys($days)));
        foreach ($days as $day => [$coats, $hats]) {
            $store->replaceDay($project, $day, [
                'query' => [],
                'page' => [new DimensionRow('https://acme.test/coats', new Metrics($coats, 100, 4.0)), new DimensionRow('https://acme.test/hats', new Metrics($hats, 50, 8.0))],
                'device' => [],
                'country' => [],
            ]);
        }
        $periods = [new DateRange('2026-01-01', '2026-03-31'), new DateRange('2026-04-01', '2026-06-30'), new DateRange('2026-07-01', '2026-09-30'), new DateRange('2026-10-01', '2026-12-31')];
        $rows = $reader->periods($project, PerformanceDimension::Page, $periods, 10);
        self::assertSame(['https://acme.test/coats', 'https://acme.test/hats'], array_column($rows, 'key'));
        self::assertSame([8, 6, 4, 2], array_map(static fn(Metrics $m): int => $m->clicks, $rows[0]['periods']));
        self::assertSame([3, 0, 5, 1], array_map(static fn(Metrics $m): int => $m->clicks, $rows[1]['periods']));
        self::assertSame(8.0, $rows[1]['periods'][2]->position);
        self::assertCount(1, $reader->periods($project, PerformanceDimension::Page, $periods, 1));
        $this->expectException(\InvalidArgumentException::class);
        $reader->periods($project, PerformanceDimension::Page, [$periods[1], $periods[0]], 10);
    }

    public function testOutreachList(): void
    {
        $project = $this->project('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fa4');
        $other = $this->project('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fa5');
        $repo = $this->app->make(OutreachRepository::class);
        self::assertTrue($repo->add($project, $this->workspaceId, 'news.test', 'mention', 'https://news.test/review', null, $this->userId));
        self::assertFalse($repo->add($project, $this->workspaceId, 'news.test', 'manual', null, null, $this->userId), 'One entry per domain.');
        self::assertTrue($repo->add($other, $this->workspaceId, 'news.test', 'manual', null, null, $this->userId));
        self::assertTrue($repo->update($project, 'news.test', 'contacted', 'Asked the editor ü'));
        self::assertFalse($repo->update($project, 'unknown.test', 'won', null));
        $entries = $repo->entries($project);
        self::assertCount(1, $entries);
        self::assertSame(['news.test', 'mention', 'https://news.test/review', null, 'contacted', 'Asked the editor ü'], [$entries[0]['domain'], $entries[0]['source'], $entries[0]['pageUrl'], $entries[0]['targetUrl'], $entries[0]['status'], $entries[0]['note']]);
        self::assertSame(1, $repo->count($project));
        self::assertTrue($repo->remove($project, 'news.test'));
        self::assertSame(0, $repo->count($project));
        self::assertSame(1, $repo->count($other), 'Projects are separate.');
        $this->db->execute('DELETE FROM projects WHERE id = ?', [$other]);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM link_outreach'));
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
