<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Audit\InMemoryAuditLogger;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Modules\Pages\Jobs\ExportPagesJob;
use SeoControlCenter\Modules\Pages\PageExport;
use SeoControlCenter\Modules\Pages\PageExportDirectory;
use SeoControlCenter\Modules\Pages\PageExportService;
use SeoControlCenter\Modules\Pages\PageSummary;
use SeoControlCenter\Tests\Support\Fakes\InMemoryPageExports;
use SeoControlCenter\Tests\Support\Fakes\InMemoryPages;
use SeoControlCenter\Tests\Support\Scenario;

final class PageExportsHttpTest extends TestCase
{
    private Scenario $s;
    private InMemoryPageExports $exports;
    private string $base;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $this->exports = $this->s->container->make(InMemoryPageExports::class);
        $this->base = '/projects/' . $this->s->acmeProject->publicId . '/pages';
        $pages = $this->s->container->make(InMemoryPages::class);
        // 25,005 URLs: many click ties and some without Google data, so paging must break ties by URL.
        for ($i = 0; $i < 25005; ++$i) {
            $pages->pages[$this->s->acmeProject->id][] = new PageSummary(sprintf('https://shop.acme.test/p/%05d', $i), $i % 9 === 0 ? null : new Metrics($i % 7, 100 + $i % 5, 5), null);
        }
        $pages->pages[$this->s->rivalProject->id][] = new PageSummary('https://rival.test/secret', null, null);
    }

    protected function tearDown(): void
    {
        $directory = $this->s->container->make(PageExportDirectory::class)->path;
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    public function testLargeResultsAreQueuedWrittenInChunksAndDownloadedOnce(): void
    {
        $browser = $this->s->loggedIn($this->s->viewer);
        $html = (string) $browser->get($this->base . '?sort=google_clicks')->getBody();
        self::assertStringContainsString('Prepare CSV export of 25,005 URLs', $html);
        self::assertStringNotContainsString('scope=all', $html);
        $response = $browser->post($this->base . '/exports', ['q' => '', 'sort' => 'google_clicks']);
        self::assertSame(303, $response->getStatusCode());
        self::assertStringEndsWith('#page-exports', $response->getHeaderLine('Location'));
        self::assertCount(1, $this->exports->exports);
        $export = array_values($this->exports->exports)[0];
        self::assertSame([PageExport::QUEUED, 25005, 'google_clicks', $this->s->viewer->id], [$export->status, $export->totalUrls, $export->sort, $export->requestedBy]);
        self::assertSame([['export_id' => $export->id, 'workspace_id' => $this->s->acmeProject->workspaceId, 'row_count' => 0]], $this->exports->dispatched);
        $audit = $this->s->container->make(AuditLogger::class);
        self::assertInstanceOf(InMemoryAuditLogger::class, $audit);
        self::assertContains('project.pages_export_requested', $audit->actions());
        self::assertStringContainsString('CSV export of 25,005 URLs queued', (string) $browser->get($this->base)->getBody());

        // A second request while one is active reuses it.
        $browser->post($this->base . '/exports', ['q' => 'p/0', 'sort' => 'url']);
        self::assertCount(1, $this->exports->exports);
        self::assertStringContainsString('already being prepared', (string) $browser->get($this->base)->getBody());

        // Time runs short after every chunk: each run writes one chunk and queues a continuation.
        $service = $this->s->container->make(PageExportService::class);
        $service->run($export->id, static fn(): int => 0);
        self::assertSame([PageExport::RUNNING, 10000], [$this->exports->exports[$export->id]->status, $this->exports->exports[$export->id]->rowCount]);
        self::assertSame(10000, $this->exports->dispatched[1]['row_count']);
        self::assertStringContainsString('Preparing', (string) $browser->get($this->base)->getBody());
        self::assertSame(404, $browser->get($this->base . '/exports/' . $export->publicId)->getStatusCode());

        // A crash after writing part of the next chunk leaves extra bytes; the retry discards them.
        $path = $this->s->container->make(PageExportDirectory::class)->path . '/' . $export->publicId . '.csv';
        file_put_contents($path, "https://shop.acme.test/partial,1,,1\r\n", FILE_APPEND);
        $service->run($export->id, static fn(): int => 0);
        $service->run($export->id, static fn(): int => 0);
        self::assertSame([PageExport::READY, 25005], [$this->exports->exports[$export->id]->status, $this->exports->exports[$export->id]->rowCount]);
        self::assertCount(3, $this->exports->dispatched);

        $download = $browser->get($this->base . '/exports/' . $export->publicId);
        self::assertSame(200, $download->getStatusCode());
        self::assertSame('text/csv; charset=utf-8', $download->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $download->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('pages-all-', $download->getHeaderLine('Content-Disposition'));
        $csv = (string) $download->getBody();
        self::assertSame(1, substr_count($csv, "\u{FEFF}"));
        self::assertStringNotContainsString('partial', $csv);
        $lines = explode("\r\n", rtrim(substr($csv, 3), "\r\n"));
        self::assertSame('URL,"Google clicks","Bing clicks","Google impressions","Period from","Period to"', $lines[0]);
        $urls = array_map(static fn(string $line): string => explode(',', $line)[0], array_slice($lines, 1));
        self::assertCount(25005, $urls);
        self::assertCount(25005, array_unique($urls));
        // Independent of the paging code: Google clicks descending, missing data last, then URL.
        $fixture = $this->s->container->make(InMemoryPages::class)->pages[$this->s->acmeProject->id];
        usort($fixture, static fn(PageSummary $a, PageSummary $b): int => (($b->google->clicks ?? -1) <=> ($a->google->clicks ?? -1)) ?: strcmp($a->url, $b->url));
        $expected = array_map(static fn(PageSummary $p): string => $p->url, $fixture);
        self::assertSame($expected, $urls);
        self::assertStringNotContainsString('rival.test', $csv);
        self::assertStringContainsString('Download CSV', (string) $browser->get($this->base)->getBody());
    }

    public function testALostLeaseQueuesAContinuationInsteadOfStalling(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->base);
        $owner->post($this->base . '/exports', ['sort' => 'url']);
        $export = $this->exports->active($this->s->acmeProject->id);
        self::assertNotNull($export);
        $service = $this->s->container->make(PageExportService::class);
        $service->run($export->id, static fn(): int => 999, static fn(): bool => false);
        self::assertSame([PageExport::RUNNING, 10000], [$this->exports->exports[$export->id]->status, $this->exports->exports[$export->id]->rowCount]);
        self::assertSame([0, 10000], array_column($this->exports->dispatched, 'row_count'));
        $service->run($export->id, static fn(): int => 999, static fn(): bool => true);
        self::assertSame([PageExport::READY, 25005], [$this->exports->exports[$export->id]->status, $this->exports->exports[$export->id]->rowCount]);
    }

    public function testDownloadsAreScopedToTheProjectAndExpire(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->base);
        $owner->post($this->base . '/exports', ['q' => '/p/1', 'sort' => 'url']);
        $export = array_values($this->exports->exports)[0];
        $service = $this->s->container->make(PageExportService::class);
        $service->run($export->id, static fn(): int => 999);
        self::assertSame(PageExport::READY, $this->exports->exports[$export->id]->status);
        $url = $this->base . '/exports/' . $export->publicId;
        self::assertSame(200, $this->s->loggedIn($this->s->viewer)->get($url)->getStatusCode());

        $rival = $this->s->loggedIn($this->s->rivalOwner);
        self::assertSame(404, $rival->get($url)->getStatusCode());
        self::assertSame(404, $rival->get('/projects/' . $this->s->rivalProject->publicId . '/pages/exports/' . $export->publicId)->getStatusCode());
        self::assertSame(404, $owner->get('/projects/' . $this->s->acmeOther->publicId . '/pages/exports/' . $export->publicId)->getStatusCode());
        self::assertSame(404, $owner->get($this->base . '/exports/0190a0b0-0000-7000-8000-000000000000')->getStatusCode());

        // Expired downloads are refused, then cleanup removes the record and the file.
        $this->exports->set($export->id, 'expiresAt', '2000-01-01 00:00:00');
        self::assertSame(404, $owner->get($url)->getStatusCode());
        $path = $this->s->container->make(PageExportDirectory::class)->path . '/' . $export->publicId . '.csv';
        self::assertFileExists($path);
        self::assertSame(1, $service->cleanup());
        self::assertFileDoesNotExist($path);
        self::assertSame([], $this->exports->exports);
    }

    public function testLimitsStalledExportsAndFailedJobs(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->base);
        $pages = $this->s->container->make(InMemoryPages::class);
        $pages->countOverrides[$this->s->acmeProject->id] = PageExportService::MAX_URLS + 1;
        self::assertStringContainsString('Narrow the URL search', (string) $owner->get($this->base)->getBody());
        $owner->post($this->base . '/exports', ['sort' => 'url']);
        self::assertSame([], $this->exports->exports);
        self::assertStringContainsString('CSV export supports up to 200,000 matching URLs', (string) $owner->get($this->base)->getBody());
        unset($pages->countOverrides[$this->s->acmeProject->id]);
        $owner->post($this->base . '/exports', ['q' => 'no-such-url', 'sort' => 'url']);
        self::assertSame([], $this->exports->exports);

        // An export without progress for six hours is failed by cleanup and can be replaced.
        $owner->post($this->base . '/exports', ['sort' => 'url']);
        $stalled = $this->exports->active($this->s->acmeProject->id);
        self::assertNotNull($stalled);
        $this->exports->set($stalled->id, 'updatedAt', '2000-01-01 00:00:00');
        $service = $this->s->container->make(PageExportService::class);
        $service->cleanup();
        self::assertSame(PageExport::FAILED, $this->exports->exports[$stalled->id]->status);
        self::assertStringContainsString('stopped making progress', (string) $owner->get($this->base)->getBody());

        // A job that fails on its last attempt marks the export failed.
        $owner->post($this->base . '/exports', ['sort' => 'url']);
        $export = $this->exports->active($this->s->acmeProject->id);
        self::assertNotNull($export);
        $service->run($export->id, static fn(): int => 0);
        $path = $this->s->container->make(PageExportDirectory::class)->path . '/' . $export->publicId . '.csv';
        self::assertFileExists($path);
        // Make the next write fail by replacing the export file with a directory.
        unlink($path);
        mkdir($path);
        try {
            (new ExportPagesJob($service))->handle(['export_id' => $export->id], new JobContext('job', 'pages.export', 3, 3, static fn(): bool => true, time() + 60));
            self::fail('The job should rethrow.');
        } catch (\RuntimeException $e) {
            self::assertSame('The export file cannot be opened.', $e->getMessage());
        } finally {
            rmdir($path);
        }
        self::assertSame(PageExport::FAILED, $this->exports->exports[$export->id]->status);
        self::assertSame('The export could not be written. Prepare a new export.', $this->exports->exports[$export->id]->error);
    }
}
