<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Database\LeaseLock;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Domain\Integrations\ConnectionRepository;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\FilteredRow;
use SeoControlCenter\Domain\SearchConsole\FreshDay;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\ProjectPropertyRepository;
use SeoControlCenter\Domain\SearchConsole\RowFilter;
use SeoControlCenter\Domain\SearchConsole\SearchConsoleExtras;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Domain\SearchConsole\SearchType;
use SeoControlCenter\Domain\SearchConsole\Sitemap;
use SeoControlCenter\Domain\SearchConsole\SyncState;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\SearchConsole\Google\GoogleAccessTokens;
use SeoControlCenter\Modules\SearchConsole\Google\GoogleTokenSet;
use SeoControlCenter\Modules\SearchConsole\Jobs\ImportSearchConsoleJob;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;

/**
 * Search performance tables on MySQL/MariaDB: batching, dictionaries,
 * idempotent day replacement, aggregation, project isolation, and the
 * import job's lock and continuation behaviour.
 */
final class SearchPerformanceStoreTest extends FullStackTestCase
{
    private SearchPerformanceStore $store;

    private SearchPerformanceReader $reader;

    private int $workspaceId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->app->make(SearchPerformanceStore::class);
        $this->reader = $this->app->make(SearchPerformanceReader::class);
        $owner = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $this->workspaceId = $owner['workspace']->id;
        $this->userId = $owner['user']->id;
    }

    public function testDaysAreReplacedAtomicallyAndAggregatedCorrectly(): void
    {
        $project = $this->project('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f70');
        $this->store->upsertDailyTotals($project, [
            new DailyMetrics('2026-09-20', new Metrics(10, 100, 4.0)),
            new DailyMetrics('2026-09-21', new Metrics(0, 0, 0.0)),
            new DailyMetrics('2026-09-22', new Metrics(30, 300, 8.0)),
        ]);
        self::assertSame(['2026-09-22', '2026-09-20'], $this->store->pendingDays($project, 10));
        self::assertSame(2, $this->store->countPendingDays($project));

        $longQuery = str_repeat('very long query ', 40); // 640 characters, stored truncated
        $this->store->replaceDay($project, '2026-09-20', [
            'query' => [$this->row('seo tools', 4, 40, 2.0), $this->row('čevapi 🍖 near me', 1, 10, 5.0), $this->row($longQuery, 1, 5, 9.0)],
            'page' => [$this->row('https://acme.test/pricing?utm=a&b=ü', 5, 50, 3.0)],
            'device' => [$this->row('DESKTOP', 6, 60, 3.0), $this->row('desktop', 1, 1, 1.0)],
            'country' => [$this->row('deu', 6, 60, 3.0)],
        ]);
        // Importing the same day again replaces rather than duplicates.
        $this->store->replaceDay($project, '2026-09-20', [
            'query' => [$this->row('seo tools', 5, 50, 2.0), $this->row('čevapi 🍖 near me', 1, 10, 5.0), $this->row($longQuery, 1, 5, 9.0)],
            'page' => [$this->row('https://acme.test/pricing?utm=a&b=ü', 5, 50, 3.0)],
            'device' => [$this->row('DESKTOP', 6, 60, 3.0)],
            'country' => [$this->row('deu', 6, 60, 3.0)],
        ]);
        $this->store->replaceDay($project, '2026-09-22', [
            'query' => [$this->row('seo tools', 10, 150, 6.0), $this->row('rank tracker', 20, 100, 1.0)],
            'page' => [],
            'device' => [],
            'country' => [],
        ]);

        self::assertSame([], $this->store->pendingDays($project, 10));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM search_console_queries WHERE project_id = ? AND date = ? AND query_id = (SELECT id FROM search_queries WHERE project_id = ? AND query = ?)', [$project, '2026-09-20', $project, 'seo tools']));
        self::assertSame(4, (int) $this->db->fetchValue('SELECT COUNT(*) FROM search_queries WHERE project_id = ?', [$project]));

        $range = new DateRange('2026-09-20', '2026-09-22');
        $totals = $this->reader->totals($project, $range);
        self::assertSame(40, $totals->clicks);
        self::assertSame(400, $totals->impressions);
        self::assertEqualsWithDelta(7.0, $totals->position, 0.01, 'Impression-weighted: (4*100 + 8*300) / 400.');
        self::assertCount(3, $this->reader->daily($project, $range));
        self::assertSame(['first' => '2026-09-20', 'last' => '2026-09-22'], $this->reader->dataSpan($project));

        $queries = $this->reader->top($project, PerformanceDimension::Query, $range, 10);
        self::assertSame(['rank tracker', 'seo tools', 'čevapi 🍖 near me'], array_slice(array_map(static fn(DimensionRow $r): string => $r->key, $queries), 0, 3));
        self::assertSame(15, $queries[1]->metrics->clicks);
        self::assertEqualsWithDelta(5.0, $queries[1]->metrics->position, 0.01, '(2*50 + 6*150) / 200');
        self::assertSame(500, mb_strlen($queries[3]->key), 'Long queries are stored truncated but stay distinct by hash.');

        self::assertSame('https://acme.test/pricing?utm=a&b=ü', $this->reader->top($project, PerformanceDimension::Page, $range, 5)[0]->key);
        $devices = $this->reader->top($project, PerformanceDimension::Device, $range, 5);
        self::assertCount(1, $devices);
        self::assertSame('desktop', $devices[0]->key);
    }

    public function testProjectsAreIsolatedAndPurgeRemovesOnlyOneProject(): void
    {
        $a = $this->project('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f71');
        $b = $this->project('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f72');
        foreach ([$a, $b] as $project) {
            $this->store->upsertDailyTotals($project, [new DailyMetrics('2026-09-20', new Metrics(1, 10, 3.0))]);
            $this->store->replaceDay($project, '2026-09-20', ['query' => [$this->row('shared query', 1, 10, 3.0)], 'page' => [], 'device' => [], 'country' => []]);
            $this->store->saveSyncState(SyncState::fresh($project, 'sc-domain:acme.test'));
        }
        $this->store->replaceDay($a, '2026-09-20', ['query' => [$this->row('only in a', 9, 90, 1.0)], 'page' => [], 'device' => [], 'country' => []]);

        $range = new DateRange('2026-09-01', '2026-09-30');
        self::assertSame(['shared query'], array_map(static fn(DimensionRow $r): string => $r->key, $this->reader->top($b, PerformanceDimension::Query, $range, 10)));
        self::assertSame(['only in a'], array_map(static fn(DimensionRow $r): string => $r->key, $this->reader->top($a, PerformanceDimension::Query, $range, 10)));

        $this->store->purge($a);
        self::assertNull($this->reader->dataSpan($a));
        self::assertNull($this->store->syncState($a));
        self::assertNotNull($this->reader->dataSpan($b));
        self::assertNotNull($this->store->syncState($b));
    }

    public function testExtrasArePreliminaryUntilFinalSummedPerSurfaceAndPurged(): void
    {
        $project = $this->project('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f90');
        $other = $this->project('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f91');
        $extras = $this->app->make(SearchConsoleExtras::class);
        $fresh = static fn(string $date, bool $complete): FreshDay => new FreshDay($date, new Metrics(3, 30, 4.5), $complete);

        $this->store->upsertDailyTotals($project, [new DailyMetrics('2026-09-20', new Metrics(10, 100, 4.0))]);
        $extras->replaceFreshDays($project, [$fresh('2026-09-20', true), $fresh('2026-09-21', true), $fresh('2026-09-22', false)]);
        $extras->replaceFreshDays($other, [$fresh('2026-09-22', false)]);
        self::assertSame(['2026-09-21', '2026-09-22'], array_map(static fn(FreshDay $d): string => $d->date, $extras->freshDays($project)));
        self::assertFalse($extras->freshDays($project)[1]->complete);
        self::assertSame(4.5, $extras->freshDays($project)[0]->metrics->position);
        $this->store->upsertDailyTotals($project, [new DailyMetrics('2026-09-21', new Metrics(11, 110, 4.0))]);
        self::assertSame(['2026-09-22'], array_map(static fn(FreshDay $d): string => $d->date, $extras->freshDays($project)), 'Final data takes over.');
        self::assertCount(1, $extras->freshDays($other), 'A project without final data shows all preliminary days.');
        // A day that was still today in Google's time zone when fetched is never complete (rows stored before 0.23.1).
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
        $extras->replaceFreshDays($other, [$fresh($today, true)]);
        self::assertFalse($extras->freshDays($other)[0]->complete);

        $extras->upsertSearchTypeDays($project, SearchType::Discover, [new DailyMetrics('2026-09-20', new Metrics(4, 400, 0.0)), new DailyMetrics('2026-09-21', new Metrics(6, 500, 0.0))]);
        $extras->upsertSearchTypeDays($project, SearchType::Image, [new DailyMetrics('2026-09-20', new Metrics(1, 100, 2.0)), new DailyMetrics('2026-09-21', new Metrics(1, 300, 6.0))]);
        $extras->upsertSearchTypeDays($project, SearchType::Discover, [new DailyMetrics('2026-09-21', new Metrics(7, 520, 0.0))]);
        $extras->upsertSearchTypeDays($other, SearchType::Discover, [new DailyMetrics('2026-09-21', new Metrics(99, 999, 0.0))]);
        $totals = $extras->searchTypeTotals($project, DateRange::endingOn('2026-09-21', 7));
        ksort($totals);
        self::assertSame(['discover', 'image'], array_keys($totals));
        self::assertSame([11, 920], [$totals['discover']->clicks, $totals['discover']->impressions], 'Upserts replace a day.');
        self::assertEqualsWithDelta(5.0, $totals['image']->position, 0.001, 'Position is weighted by impressions.');
        self::assertSame([], $extras->searchTypeTotals($project, DateRange::endingOn('2026-09-19', 7)));
        self::assertSame('2026-09-21', $extras->searchTypeLastDate($project));
        self::assertNull($extras->searchTypeLastDate($this->project('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f92')));
        self::assertEquals([new DailyMetrics('2026-09-20', new Metrics(1, 100, 2.0)), new DailyMetrics('2026-09-21', new Metrics(1, 300, 6.0))], $extras->searchTypeDaily($project, SearchType::Image, DateRange::endingOn('2026-09-21', 7)));
        self::assertSame([], $extras->searchTypeDaily($project, SearchType::Video, DateRange::endingOn('2026-09-21', 7)));
        self::assertNull($extras->sitemapsRefreshedAt($project));

        $long = 'https://acme.test/' . str_repeat('ß', 1900) . '.xml';
        $extras->replaceSitemaps($project, [
            new Sitemap('https://acme.test/sitemap.xml', true, false, '2026-09-01 10:00:00', '2026-09-20 08:00:00', 0, 0, 0),
            new Sitemap($long, false, true, null, null, 2, 1, 120),
        ]);
        $extras->replaceSitemaps($other, [new Sitemap('https://other.test/sitemap.xml', false, false, null, null, 0, 0, 5)]);
        self::assertNotNull($extras->sitemapsRefreshedAt($project));
        $sitemaps = $extras->sitemaps($project);
        self::assertSame([$long, 'https://acme.test/sitemap.xml'], array_map(static fn(Sitemap $s): string => $s->url, $sitemaps), 'Problems first.');
        self::assertEquals(new Sitemap('https://acme.test/sitemap.xml', true, false, '2026-09-01 10:00:00', '2026-09-20 08:00:00', 0, 0, 0), $sitemaps[1]);
        $extras->replaceSitemaps($project, [$sitemaps[1]]);
        self::assertCount(1, $extras->sitemaps($project));

        $this->store->purge($project);
        self::assertSame([], $extras->freshDays($project));
        self::assertSame([], $extras->searchTypeTotals($project, DateRange::endingOn('2026-09-21', 7)));
        self::assertSame([], $extras->sitemaps($project));
        self::assertCount(1, $extras->sitemaps($other), 'Purge removes only one project.');
    }

    public function testMonthlyRollupsGiveTheSameResultsAsDailyRows(): void
    {
        $project = $this->project('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f73');
        $days = [];
        $date = new \DateTimeImmutable('2026-06-25');
        for ($i = 0; $i < 90; ++$i, $date = $date->modify('+1 day')) { // 2026-06-25 .. 2026-09-22
            $days[] = $date->format('Y-m-d');
        }
        $this->store->upsertDailyTotals($project, array_map(static fn(string $d): DailyMetrics => new DailyMetrics($d, new Metrics(10, 100, 5.0)), $days));
        foreach ($days as $i => $day) {
            $this->store->replaceDay($project, $day, [
                'query' => [$this->row('daily', 1 + $i % 3, 10 + $i, 1.0 + ($i % 7) / 3), $this->row('q' . ($i % 11), $i % 5, 7 + $i % 13, 2.5 + ($i % 4))],
                'page' => [$this->row('https://acme.test/p' . ($i % 4), $i % 6, 20 + $i, 3.25)],
                'device' => [],
                'country' => [],
            ]);
        }
        $ranges = [new DateRange('2026-06-25', '2026-09-22'), new DateRange('2026-07-01', '2026-08-31'), new DateRange('2026-07-15', '2026-09-03'), new DateRange('2026-08-02', '2026-08-30')];
        $snapshot = fn(): array => array_map(fn(DateRange $r): array => [
            $this->reader->top($project, PerformanceDimension::Query, $r, 50),
            $this->reader->top($project, PerformanceDimension::Page, $r, 50),
        ], $ranges);
        $fromDays = $snapshot();

        self::assertSame(4, $this->store->refreshRollups($project, static fn(): bool => true), 'June, July, August and September.');
        self::assertSame(0, $this->store->refreshRollups($project, static fn(): bool => true), 'Nothing left to rebuild.');
        self::assertEquals($fromDays, $snapshot(), 'Rollups return exactly what the daily rows return.');

        // Re-importing a day invalidates only its month until the next rebuild.
        $this->store->replaceDay($project, '2026-07-10', ['query' => [$this->row('late correction', 99, 999, 1.0)], 'page' => [], 'device' => [], 'country' => []]);
        $july = new DateRange('2026-07-01', '2026-07-31');
        self::assertSame('late correction', $this->reader->top($project, PerformanceDimension::Query, $july, 1)[0]->key);
        self::assertSame(1, $this->store->refreshRollups($project, static fn(): bool => true));
        self::assertSame('late correction', $this->reader->top($project, PerformanceDimension::Query, $july, 1)[0]->key);

        // A month with a day still waiting for import is not rolled up.
        $this->store->upsertDailyTotals($project, [new DailyMetrics('2026-05-31', new Metrics(1, 10, 3.0))]);
        $this->store->replaceDay($project, '2026-05-30', ['query' => [$this->row('may', 1, 1, 1.0)], 'page' => [], 'device' => [], 'country' => []]);
        self::assertSame(0, $this->store->refreshRollups($project, static fn(): bool => true));
        self::assertSame(0, $this->store->refreshRollups($project, static fn(): bool => false), 'Stops when time runs out.');

        $this->store->purge($project);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM search_console_queries_monthly WHERE project_id = ?', [$project]));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM search_console_rollup_months WHERE project_id = ?', [$project]));
    }

    public function testImportJobHonoursTheLockAndQueuesAContinuation(): void
    {
        $project = $this->project('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f73');
        $google = new FakeHttpClient();
        $google->on('POST', 'https://www.googleapis.com/webmasters/v3/sites/', static function (string $url, array $headers, ?string $body): HttpResponse {
            $request = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
            $rows = ($request['dimensions'] ?? []) === ['date']
                ? [['keys' => ['2026-09-20'], 'clicks' => 1, 'impressions' => 10, 'position' => 2.0], ['keys' => ['2026-09-21'], 'clicks' => 2, 'impressions' => 20, 'position' => 3.0]]
                : [['keys' => ['row'], 'clicks' => 1, 'impressions' => 5, 'position' => 2.0]];

            return new HttpResponse(200, json_encode(['rows' => $rows], JSON_THROW_ON_ERROR));
        });
        $this->app->instance(HttpClient::class, $google);

        $connection = $this->app->make(ConnectionRepository::class)->upsert($this->workspaceId, 'google', 'seo@acme.test', 'webmasters.readonly', $this->userId);
        $this->app->make(GoogleAccessTokens::class)->store($connection->id, new GoogleTokenSet('ya29.valid', '1//refresh', 3600, 'webmasters.readonly'));
        $this->app->make(ProjectPropertyRepository::class)->link($project, $connection->id, 'sc-domain:acme.test', $this->userId);
        self::assertSame([['projectId' => $project, 'workspaceId' => $this->workspaceId]], $this->app->make(ProjectPropertyRepository::class)->linkedProjects());

        $job = $this->app->make(\SeoControlCenter\Core\Queue\JobRegistry::class)->handler(ImportSearchConsoleJob::TYPE);
        $context = static fn(int $seconds): JobContext => new JobContext('job', ImportSearchConsoleJob::TYPE, 1, 3, static fn(): bool => true, time() + $seconds);

        // Another run holds the project lock: nothing happens.
        $other = new LeaseLock($this->newConnection(), 'search_console.import:' . $project, 60);
        self::assertTrue($other->acquire());
        $job->handle(['project_id' => $project], $context(600));
        self::assertSame([], $google->requests);
        $other->release();

        // Almost no time left: totals are read, detail waits for a continuation job.
        $job->handle(['project_id' => $project, 'workspace_id' => $this->workspaceId], $context(5));
        self::assertSame(2, $this->store->countPendingDays($project));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM jobs WHERE type = ? AND status = 'queued'", [ImportSearchConsoleJob::TYPE]));

        // Enough time: the backlog is finished and no further job is queued.
        $job->handle(['project_id' => $project], $context(600));
        self::assertSame(0, $this->store->countPendingDays($project));
        self::assertSame(SyncState::OK, $this->store->syncState($project)?->status);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM jobs WHERE type = ? AND status = 'queued'", [ImportSearchConsoleJob::TYPE]));
    }

    public function testOverviewComparisonsExcludeAbsentRowsAndCountEmptyImportedDays(): void
    {
        $project = $this->project('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f91');
        $other = $this->project('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f92');
        $range = new DateRange('2026-09-20', '2026-09-21');
        $rows = $this->dimensionRows(...);
        $this->store->replaceDay($project, '2026-09-18', $rows([$this->row('gain', 0, 100, 8), $this->row('loss', 20, 200, 3)], [$this->row('https://acme.test/a', 3, 50, 5)]));
        $this->store->replaceDay($project, '2026-09-19', $rows([]));
        $this->store->replaceDay($project, '2026-09-20', $rows([$this->row('gain', 30, 300, 4), $this->row('loss', 5, 100, 5), $this->row('new absent baseline', 200, 1000, 1)], [$this->row('https://acme.test/a', 8, 100, 3)]));
        $this->store->replaceDay($project, '2026-09-21', $rows([]));
        $this->store->replaceDay($other, '2026-09-20', $rows([$this->row('other tenant', 900, 1000, 1)]));
        self::assertSame(2, $this->reader->coveredDays($project, $range));
        self::assertSame(2, $this->reader->coveredDays($project, $range->previous()));
        $comparison = $this->reader->compared($project, PerformanceDimension::Query, $range, 1);
        self::assertSame(2, $comparison['total']);
        self::assertCount(1, $comparison['rows']);
        self::assertSame('gain', $comparison['rows'][0]['key']);
        self::assertSame(0, $comparison['rows'][0]['previous']->clicks);
        self::assertSame(30, $comparison['rows'][0]['current']->clicks);
        self::assertSame(4.0, $comparison['rows'][0]['current']->position);
        $pages = $this->reader->compared($project, PerformanceDimension::Page, $range, 10);
        self::assertSame(1, $pages['total']);
        self::assertSame(8, $pages['rows'][0]['current']->clicks);
        self::assertSame([], $this->reader->compared($other, PerformanceDimension::Query, $range, 10)['rows']);
        $this->store->upsertDailyTotals($project, [new DailyMetrics('2026-09-22', Metrics::zero()), new DailyMetrics('2026-09-23', new Metrics(1, 10, 3))]);
        self::assertSame(3, $this->reader->coveredDays($project, new DateRange('2026-09-20', '2026-09-23')), 'Recorded empty dates are complete; positive dates still need breakdown imports.');
        self::assertSame(['2026-09-20', '2026-09-21', '2026-09-22'], $this->reader->coveredDates($project, new DateRange('2026-09-20', '2026-09-23')), 'coveredDates lists exactly what coveredDays counts, ascending and without duplicates.');
        self::assertSame([], $this->reader->coveredDates($other, new DateRange('2026-09-21', '2026-09-23')));
        self::assertSame(0, $this->reader->coveredDays($other, new DateRange('2026-09-22', '2026-09-23')), 'Zero-impression coverage stays project scoped.');
    }

    public function testBoundedMoversKeepLossesWhenHundredsOfQueriesGain(): void
    {
        $project = $this->project('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f93');
        $previous = [$this->row('small loss', 2, 100, 3)];
        $current = [$this->row('small loss', 1, 100, 4)];
        for ($i = 0; $i < 501; ++$i) {
            $previous[] = $this->row('gain ' . $i, 1, 1000, 5);
            $current[] = $this->row('gain ' . $i, 500, 1000, 3);
        }
        $this->store->replaceDay($project, '2026-09-19', $this->dimensionRows($previous));
        $this->store->replaceDay($project, '2026-09-20', $this->dimensionRows($current));
        $comparison = $this->reader->compared($project, PerformanceDimension::Query, new DateRange('2026-09-20', '2026-09-20'), 6);
        self::assertSame(502, $comparison['total']);
        self::assertCount(6, $comparison['rows']);
        self::assertContains('small loss', array_column($comparison['rows'], 'key'));
    }

    public function testFilteredListsSearchBeyondTheTopRowsAndStayInTheProject(): void
    {
        $project = $this->project('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f94');
        $other = $this->project('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f95');
        $current = [$this->row('what is a gravel bike', 0, 5000, 11.0), $this->row('how to fix a bike', 2, 900, 9.0), $this->row('bike_100%', 1, 10, 30.0)];
        // 600 queries with clicks push the zero-click query out of the top 500.
        for ($i = 0; $i < 600; ++$i) {
            $current[] = $this->row('popular ' . $i, 10, 100, 2.0);
        }
        $this->store->replaceDay($project, '2026-09-19', $this->dimensionRows([$this->row('how to fix a bike', 20, 400, 4.0), $this->row('old query', 9, 90, 7.0)], [$this->row('https://acme.test/blog/a', 3, 30, 5.0)]));
        $this->store->replaceDay($project, '2026-09-20', $this->dimensionRows($current, [$this->row('https://acme.test/blog/a', 1, 60, 6.0), $this->row('https://acme.test/shop', 9, 90, 2.0)]));
        $this->store->replaceDay($other, '2026-09-20', $this->dimensionRows([$this->row('what is a rival', 0, 9000, 5.0)]));
        $range = new DateRange('2026-09-20', '2026-09-20');
        $keys = static fn(array $result): array => array_map(static fn(FilteredRow $r): string => $r->key, $result['rows']);

        self::assertNotContains('what is a gravel bike', array_map(static fn(DimensionRow $r): string => $r->key, $this->reader->top($project, PerformanceDimension::Query, $range, 500)));
        $lowCtr = $this->reader->matching($project, PerformanceDimension::Query, $range, new RowFilter(minImpressions: 500, maxCtr: 0.01, sort: 'impressions'), 10);
        self::assertSame(['what is a gravel bike', 'how to fix a bike'], $keys($lowCtr));
        self::assertSame(2, $lowCtr['total']);
        self::assertEqualsWithDelta(11.0, $lowCtr['rows'][0]->current->position, 0.001);

        self::assertSame(['how to fix a bike', 'what is a gravel bike'], $keys($this->reader->matching($project, PerformanceDimension::Query, $range, new RowFilter(['HOW TO', 'what is']), 10)));
        self::assertSame(['what is a gravel bike'], $keys($this->reader->matching($project, PerformanceDimension::Query, $range, new RowFilter(['what', 'gravel'], containsAll: true), 10)));
        self::assertSame(['bike_100%'], $keys($this->reader->matching($project, PerformanceDimension::Query, $range, new RowFilter(['_100%']), 10)));
        self::assertSame(['how to fix a bike'], $keys($this->reader->matching($project, PerformanceDimension::Query, $range, new RowFilter(['bike'], exclude: ['gravel', '%']), 10)));

        $losers = $this->reader->matching($project, PerformanceDimension::Query, $range, new RowFilter(sort: 'clicks_change', descending: false), 2);
        self::assertSame(['how to fix a bike', 'old query'], $keys($losers));
        self::assertSame(20, $losers['rows'][0]->previous?->clicks);
        self::assertSame(0, $losers['rows'][1]->current->impressions);
        self::assertSame(604, $losers['total']);

        $pages = $this->reader->matching($project, PerformanceDimension::Page, $range, new RowFilter(['/blog/'], compare: true), 10);
        self::assertSame(['https://acme.test/blog/a'], $keys($pages));
        self::assertSame(30, $pages['rows'][0]->previous?->impressions);

        self::assertSame(['what is a gravel bike', 'how to fix a bike'], $keys($this->reader->matching($project, PerformanceDimension::Query, $range, new RowFilter(sort: 'impressions', minWords: 4), 10)));
        self::assertSame(['old query'], $keys($this->reader->matching($project, PerformanceDimension::Query, $range, new RowFilter(presence: 'lost'), 10)));
        $new = $this->reader->matching($project, PerformanceDimension::Query, $range, new RowFilter(['bike'], presence: 'new'), 10);
        self::assertSame(['bike_100%', 'what is a gravel bike'], $keys($new));
        self::assertSame(1, $new['totals']->clicks);
        self::assertSame(5010, $new['totals']->impressions);

        $paged = $this->reader->matching($project, PerformanceDimension::Query, $range, new RowFilter(['popular']), 50, 580);
        self::assertSame(600, $paged['total']);
        self::assertCount(20, $paged['rows']);
        self::assertSame(600, $this->reader->matching($project, PerformanceDimension::Query, $range, new RowFilter(['popular']), 50, 650)['total']);
    }

    /** @param list<DimensionRow> $queries
     * @param list<DimensionRow> $pages
     * @return array<'query'|'page'|'device'|'country', list<DimensionRow>>
     */
    private function dimensionRows(array $queries, array $pages = []): array
    {
        return ['query' => $queries, 'page' => $pages, 'device' => [], 'country' => []];
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

    private function row(string $key, int $clicks, int $impressions, float $position): DimensionRow
    {
        return new DimensionRow($key, new Metrics($clicks, $impressions, $position));
    }
}
