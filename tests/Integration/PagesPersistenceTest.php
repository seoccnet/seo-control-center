<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\QueryPageRow;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Bing\BingDataRepository;
use SeoControlCenter\Modules\Bing\BingWeeklyRow;
use SeoControlCenter\Modules\Pages\PageRepository;
use SeoControlCenter\Modules\Research\ResearchRepository;
use SeoControlCenter\Modules\SiteAudit\PageResult;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;

final class PagesPersistenceTest extends FullStackTestCase
{
    public function testCatalogSearchEscapesWildcardsAndSortsBeforePagination(): void
    {
        $this->createUser('filter@pages.test', 'Filters');
        $publicId = $this->createProject($this->browserFor('filter@pages.test'), 'Filters', 'pages.test');
        $projectId = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$publicId]);
        $this->app->make(SearchPerformanceStore::class)->replaceDay($projectId, '2026-10-01', ['page' => [
            new DimensionRow('https://pages.test/aaa', new Metrics(1, 10, 5)),
            new DimensionRow('https://pages.test/zzz', new Metrics(50, 500, 5)),
            new DimensionRow('https://pages.test/offer_100%!', new Metrics(2, 20, 5)),
            new DimensionRow('https://pages.test/offerX100abc', new Metrics(3, 30, 5)),
        ]]);
        $repository = $this->app->make(PageRepository::class);
        $range = new DateRange('2026-10-01', '2026-10-01');
        self::assertSame(1, $repository->count($projectId, '_100%!'));
        self::assertSame('https://pages.test/offer_100%!', $repository->list($projectId, $range, 1, 0, '_100%!')[0]->url);
        self::assertSame('https://pages.test/zzz', $repository->list($projectId, $range, 1, 0, '', 'google_clicks')[0]->url);
        self::assertSame(0, $repository->count($projectId, 'missing'));
        self::assertSame(['https://pages.test/offer_100%!'], array_column($repository->export($projectId, $range, '_100%!'), 'url'));
        self::assertSame('https://pages.test/zzz', $repository->export($projectId, $range, '', 'google_clicks')[0]->url);
        $many = [];
        for ($i = 0; $i < 130; ++$i) {
            $many[] = new DimensionRow('https://pages.test/bulk/' . sprintf('%03d', $i), new Metrics($i, 200, 5));
        }
        $this->app->make(SearchPerformanceStore::class)->replaceDay($projectId, '2026-10-01', ['page' => $many]);
        $export = $repository->export($projectId, $range, '/bulk/', 'google_clicks');
        self::assertCount(130, $export);
        self::assertSame('https://pages.test/bulk/129', $export[0]->url);
        self::assertSame('https://pages.test/bulk/000', $export[129]->url);
        self::assertCount(100, $repository->list($projectId, $range, 1000, 0, '/bulk/'));
    }

    public function testBacklinkCatalogPreservesUnicodeWithLatin1DatabaseDefault(): void
    {
        $database = (string) $this->db->fetchValue('SELECT DATABASE()');
        self::assertMatchesRegularExpression('/^[a-zA-Z0-9_]+_test$/', $database);
        $defaults = $this->db->fetchOne('SELECT DEFAULT_CHARACTER_SET_NAME AS charset, DEFAULT_COLLATION_NAME AS collation FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$database]);
        self::assertNotNull($defaults);
        self::assertMatchesRegularExpression('/^[a-zA-Z0-9_]+$/', (string) $defaults['charset']);
        self::assertMatchesRegularExpression('/^[a-zA-Z0-9_]+$/', (string) $defaults['collation']);
        $this->db->statement("ALTER DATABASE `$database` CHARACTER SET latin1 COLLATE latin1_swedish_ci");
        try {
            $owner = $this->createUser('owner@encoding.test', 'Encoding');
            self::assertNotNull($owner['workspace']);
            $publicId = $this->createProject($this->browserFor('owner@encoding.test'), 'Encoding', 'encoding.test');
            $projectId = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$publicId]);
            $urls = ['https://encoding.test/café/植物', 'https://encoding.test/Café/植物'];
            $research = $this->app->make(ResearchRepository::class);
            $research->queue($projectId, $owner['workspace']->id, 'bl_links', '', [], $owner['user']->id);
            $research->complete($projectId, 'bl_links', '', array_map(static fn(string $url): array => ['source_url' => 'https://source.test/a', 'target_url' => $url, 'anchor' => '', 'dofollow' => true], $urls));
            $repository = $this->app->make(PageRepository::class);
            $range = new DateRange('2026-10-01', '2026-10-02');
            self::assertSame(2, $repository->count($projectId));
            self::assertCount(2, $repository->list($projectId, $range, 50, 0));
            foreach ($urls as $url) {
                self::assertSame($url, $repository->find($projectId, $url, $range)?->url);
            }
        } finally {
            $this->db->statement("ALTER DATABASE `$database` CHARACTER SET " . $defaults['charset'] . ' COLLATE ' . $defaults['collation']);
        }
    }

    public function testCatalogUnifiesSourcesAndPaginatesAllUrlsWithoutMixingProjects(): void
    {
        $owner = $this->createUser('owner@pages.test', 'Pages');
        self::assertNotNull($owner['workspace']);
        $browser = $this->browserFor('owner@pages.test');
        $publicId = $this->createProject($browser, 'Pages', 'pages.test');
        $otherPublic = $this->createProject($browser, 'Other', 'other.test');
        $projectId = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$publicId]);
        $otherId = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$otherPublic]);
        $store = $this->app->make(SearchPerformanceStore::class);
        $url = 'https://pages.test/pricing?x=1&y=2';
        $store->replaceDay($projectId, '2026-10-01', ['page' => [new DimensionRow($url, new Metrics(9, 100, 4))]]);
        $store->replaceDay($projectId, '2026-10-02', ['page' => [new DimensionRow($url, new Metrics(3, 300, 8))]]);
        $store->replaceDay($otherId, '2026-10-02', ['page' => [new DimensionRow($url, new Metrics(999, 999, 1)), new DimensionRow('https://other.test/secret', new Metrics(1, 1, 1))]]);
        $this->app->make(BingDataRepository::class)->replaceData($projectId, [], [new BingWeeklyRow('2026-10-02', PerformanceDimension::Page, new DimensionRow($url, new Metrics(2, 20, 5)))]);
        $audits = $this->app->make(SiteAuditRepository::class);
        $audit = $audits->create($projectId, $owner['workspace']->id, 'https://pages.test/crawl-only', 100, 'manual', $owner['user']->id);
        $page = $audits->pendingPages($audit->id, 1)[0];
        $audits->savePage($audit, $page, new PageResult('done', 404));
        $audits->finish($audit->id, 'completed', 0, ['error' => 0, 'warning' => 0, 'notice' => 0], [], null);
        $research = $this->app->make(ResearchRepository::class);
        $research->queue($projectId, $owner['workspace']->id, 'bl_links', '', [], $owner['user']->id);
        $research->complete($projectId, 'bl_links', '', [['source_url' => 'https://source.test/a', 'target_url' => 'https://pages.test/link-only', 'anchor' => '', 'dofollow' => true]]);

        $repository = $this->app->make(PageRepository::class);
        $range = new DateRange('2026-10-01', '2026-10-02');
        self::assertSame(2, $repository->importedPageDays($projectId, $range));
        $export = $repository->export($projectId, $range);
        self::assertSame($repository->count($projectId), count($export));
        self::assertNotContains('https://other.test/secret', array_column($export, 'url'));
        self::assertSame(1, $repository->importedPageDays($otherId, $range));
        self::assertSame(0, $repository->importedPageDays($projectId, $range->previous()));
        self::assertSame(3, $repository->count($projectId));
        $rows = $repository->list($projectId, $range, 2, 0);
        self::assertCount(2, $rows);
        self::assertCount(1, $repository->list($projectId, $range, 2, 2));
        self::assertNull($repository->find($projectId, 'https://other.test/secret', $range));
        $summary = $repository->find($projectId, $url, $range);
        self::assertNotNull($summary);
        self::assertNotNull($summary->google);
        self::assertSame(12, $summary->google->clicks);
        self::assertSame(7.0, $summary->google->position);
        self::assertSame(2, $summary->bing?->clicks);
        self::assertNull($repository->find($projectId, 'https://pages.test/crawl-only', $range)?->google);
        self::assertSame($page->id, $audits->findPage($audit->id, 'https://pages.test/crawl-only')?->id);
        self::assertNull($audits->findPage($audit->id, 'https://other.test/secret'));
    }

    public function testJointDataIsReplacedBackfilledAndPurgedWithoutLosingPageTotals(): void
    {
        $owner = $this->createUser('owner@joint.test', 'Joint');
        $publicId = $this->createProject($this->browserFor('owner@joint.test'), 'Joint', 'joint.test');
        $id = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$publicId]);
        $store = $this->app->make(SearchPerformanceStore::class);
        $store->upsertDailyTotals($id, [new DailyMetrics('2026-10-02', new Metrics(10, 200, 5)), new DailyMetrics('2026-08-01', new Metrics(1, 10, 10))]);
        $store->replaceDay($id, '2026-10-02', ['page' => [new DimensionRow('https://joint.test/a', new Metrics(10, 200, 5))]]);
        $store->replaceDay($id, '2026-08-01', []);
        self::assertSame(['2026-10-02'], $store->pendingQueryPageDays($id, 28));
        $pairs = [new QueryPageRow('seo tools', 'https://joint.test/a', new Metrics(4, 100, 4)), new QueryPageRow('seo tools', 'https://joint.test/b', new Metrics(1, 50, 10))];
        $store->replaceQueryPages($id, '2026-10-02', $pairs);
        $store->replaceQueryPages($id, '2026-10-02', $pairs);
        self::assertSame([], $store->pendingQueryPageDays($id, 28));
        $repository = $this->app->make(PageRepository::class);
        $range = new DateRange('2026-10-02', '2026-10-02');
        self::assertCount(2, $repository->queries($id, $range));
        self::assertCount(1, $repository->queries($id, $range, 'https://joint.test/b'));
        self::assertSame(10, $repository->find($id, 'https://joint.test/a', $range)?->google?->clicks, 'Joint totals do not overwrite page totals.');
        self::assertSame(['first' => '2026-10-02', 'last' => '2026-10-02'], $repository->queryPageSpan($id));
        $store->purge($id);
        self::assertSame([], $repository->queries($id, $range));
        self::assertNull($repository->queryPageSpan($id));
    }
}
