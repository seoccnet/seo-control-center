<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Pages\PageExport;
use SeoControlCenter\Modules\Pages\PageExportDirectory;
use SeoControlCenter\Modules\Pages\PageExportRepository;
use SeoControlCenter\Modules\Pages\PageExportService;
use SeoControlCenter\Modules\Pages\PageRepository;
use SeoControlCenter\Modules\Pages\PageSummary;
use SeoControlCenter\Modules\Research\ResearchRepository;

final class PageExportPersistenceTest extends FullStackTestCase
{
    public function testKeysetChunksMatchTheFullOrderForEverySort(): void
    {
        $owner = $this->createUser('chunks@pages.test', 'Chunks');
        self::assertNotNull($owner['workspace']);
        $publicId = $this->createProject($this->browserFor('chunks@pages.test'), 'Chunks', 'chunks.test');
        $projectId = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$publicId]);
        $rows = [];
        for ($i = 0; $i < 23; ++$i) {
            // Few distinct click values force ties that only the URL can order.
            $rows[] = new DimensionRow(sprintf('https://chunks.test/p/%02d', $i), new Metrics($i % 3, 10 + $i % 4, 5));
        }
        // URLs differing only in case are distinct pages and must both appear once.
        $rows[] = new DimensionRow('https://chunks.test/Case', new Metrics(1, 11, 5));
        $rows[] = new DimensionRow('https://chunks.test/case', new Metrics(1, 11, 5));
        $this->app->make(SearchPerformanceStore::class)->replaceDay($projectId, '2026-10-01', ['page' => $rows]);
        // Backlink-only URLs have no Google or Bing metrics and sort last.
        $research = $this->app->make(ResearchRepository::class);
        $research->queue($projectId, $owner['workspace']->id, 'bl_links', '', [], $owner['user']->id);
        $research->complete($projectId, 'bl_links', '', [
            ['source_url' => 'https://source.test/a', 'target_url' => 'https://chunks.test/z-no-data', 'anchor' => '', 'dofollow' => true],
            ['source_url' => 'https://source.test/a', 'target_url' => 'https://chunks.test/a-no-data', 'anchor' => '', 'dofollow' => true],
        ]);

        $pages = $this->app->make(PageRepository::class);
        $range = new DateRange('2026-10-01', '2026-10-01');
        self::assertSame(27, $pages->count($projectId));
        foreach (['url', 'google_clicks', 'impressions', 'bing_clicks'] as $sort) {
            $paged = [];
            $after = null;
            do {
                $chunk = $pages->exportChunk($projectId, $range, '', $sort, $after, 4);
                foreach ($chunk as $page) {
                    $paged[] = $page->url;
                }
                if ($chunk !== []) {
                    $last = $chunk[count($chunk) - 1];
                    $after = ['key' => match ($sort) {
                        'google_clicks' => $last->google?->clicks, 'impressions' => $last->google?->impressions, 'bing_clicks' => $last->bing?->clicks, default => 0,
                    } ?? -1, 'url' => $last->url];
                }
            } while (count($chunk) === 4);
            $full = array_map(static fn(PageSummary $p): string => $p->url, $pages->export($projectId, $range, '', $sort));
            self::assertSame($full, $paged, 'Chunked order for ' . $sort);
            self::assertCount(27, array_unique($paged));
        }
        self::assertSame(['https://chunks.test/p/02', 'https://chunks.test/p/05'], array_map(static fn(PageSummary $p): string => $p->url, $pages->exportChunk($projectId, $range, '/p/', 'google_clicks', null, 2)));
        self::assertSame('https://chunks.test/z-no-data', $pages->exportChunk($projectId, $range, '', 'google_clicks', ['key' => -1, 'url' => 'https://chunks.test/a-no-data'], 5)[0]->url);
    }

    public function testRepositoryAndServiceWriteScopedExpiringFiles(): void
    {
        // Without an import span the export reads the 28 days before today: pin today so the 2026-10-01 fixture day is inside.
        $this->app->instance(\SeoControlCenter\Core\Support\Clock::class, new \SeoControlCenter\Core\Support\FrozenClock(new \DateTimeImmutable('2026-10-08 08:00:00', new \DateTimeZone('UTC'))));
        $owner = $this->createUser('files@pages.test', 'Files');
        self::assertNotNull($owner['workspace']);
        $publicId = $this->createProject($this->browserFor('files@pages.test'), 'Files', 'files.test');
        $projectId = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$publicId]);
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($owner['workspace']->id, $publicId);
        self::assertNotNull($project);
        $this->app->make(SearchPerformanceStore::class)->replaceDay($projectId, '2026-10-01', ['page' => [
            new DimensionRow('https://files.test/a', new Metrics(5, 50, 3)),
            new DimensionRow('https://files.test/=formula', new Metrics(1, 10, 3)),
        ]]);
        $directory = sys_get_temp_dir() . '/scc-page-export-it-' . bin2hex(random_bytes(4));
        $this->app->instance(PageExportDirectory::class, new PageExportDirectory($directory));
        $service = $this->app->make(PageExportService::class);
        $repository = $this->app->make(PageExportRepository::class);
        try {
            $requested = $service->request($owner['user'], $project, '', 'google_clicks', ClientInfo::cli());
            self::assertTrue($requested['created']);
            $export = $requested['export'];
            self::assertSame([PageExport::QUEUED, 2, $owner['user']->id], [$export->status, $export->totalUrls, $export->requestedBy]);
            self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM jobs WHERE type = 'pages.export' AND idempotency_key = ?", ['pages.export:' . $export->id . ':0']));
            self::assertFalse($service->request($owner['user'], $project, 'other', 'url', ClientInfo::cli())['created']);
            self::assertNull($repository->find($projectId + 1000, $export->publicId));
            self::assertSame($export->id, $repository->active($projectId)?->id);

            $service->run($export->id, static fn(): int => 999);
            $ready = $repository->find($projectId, $export->publicId);
            self::assertNotNull($ready);
            self::assertSame([PageExport::READY, 2, null, null], [$ready->status, $ready->rowCount, $ready->cursorKey, $ready->cursorUrl]);
            self::assertNull($repository->active($projectId));
            self::assertFalse($repository->progress($export->id, 9, 9, null, '2026-10-08 00:00:00'));
            $file = $service->download($project, $export->publicId);
            $csv = (string) file_get_contents($file['path']);
            self::assertSame($ready->fileBytes, strlen($csv));
            self::assertStringStartsWith("\u{FEFF}URL,", $csv);
            self::assertLessThan(strpos($csv, 'https://files.test/=formula,1'), strpos($csv, 'https://files.test/a,5'));

            $this->db->execute('UPDATE page_exports SET expires_at = ? WHERE id = ?', ['2000-01-01 00:00:00', $export->id]);
            self::assertCount(1, $repository->due('2026-10-08 00:00:00', '2000-01-01 00:00:00', 10));
            self::assertSame(1, $service->cleanup());
            self::assertFileDoesNotExist($file['path']);
            self::assertNull($repository->byId($export->id));
        } finally {
            foreach (glob($directory . '/*') ?: [] as $leftover) {
                unlink($leftover);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
