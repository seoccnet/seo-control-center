<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Modules\BotLogs\BotLogImport;
use SeoControlCenter\Modules\BotLogs\BotLogService;
use SeoControlCenter\Tests\Support\Fakes\InMemoryBotLogs;
use SeoControlCenter\Tests\Support\Scenario;

/** Search bot log analysis (1.2.0): upload, background reading, report and permissions. */
final class BotLogsHttpTest extends TestCase
{
    public function testGzipLogIsReadIntoAReportAndDeleted(): void
    {
        $s = new Scenario();
        $store = $s->container->get(InMemoryBotLogs::class);
        $service = $s->container->get(BotLogService::class);
        $google = '"Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)"';
        $lines = [];
        for ($i = 0; $i < 30; ++$i) {
            $lines[] = '66.249.66.1 - - [0' . (1 + $i % 3) . '/Nov/2026:10:00:00 +0000] "GET /shop?sort=price&page=' . $i . ' HTTP/1.1" 200 100 "-" ' . $google;
        }
        $lines[] = '66.249.66.1 - - [04/Nov/2026:10:00:00 +0000] "GET /old-page HTTP/1.1" 404 100 "-" ' . $google;
        // A tool using Googlebot's name from an address outside Google's ranges.
        $lines[] = '203.0.113.9 - - [04/Nov/2026:10:00:00 +0000] "GET /old-page HTTP/1.1" 404 100 "-" ' . $google;
        $store->ranges = ['googlebot' => ['66.249.64.0/19']];
        $lines[] = '40.77.167.1 - - [04/Nov/2026:11:00:00 +0000] "GET /about HTTP/1.1" 301 0 "-" "Mozilla/5.0 (compatible; bingbot/2.0)"';
        $lines[] = '9.9.9.9 - - [04/Nov/2026:11:00:00 +0000] "GET /about HTTP/1.1" 200 10 "-" "Mozilla/5.0 Firefox/130"';
        $s->container->get(SearchPerformanceStore::class)->replaceDay($s->acmeProject->id, '2026-11-02', ['query' => [], 'page' => [
            new DimensionRow('https://shop.acme.test/old-page', new Metrics(4, 50, 3.0)),
            new DimensionRow('https://shop.acme.test/best-seller', new Metrics(40, 500, 2.0)),
        ]]);

        $path = $service->incomingPath();
        file_put_contents($path, (string) gzencode(implode("\n", $lines) . "\n"));
        $import = $service->accept($s->owner, $s->acmeProject, $path, '../access_log.gz', ClientInfo::cli());
        self::assertSame('access_log.gz', $import->fileName);
        self::assertFileDoesNotExist($path);
        self::assertSame([['id' => $import->id, 'offset' => 0]], $store->scheduled);

        $service->run($import->id, static fn(): int => 600);
        $done = $store->imports[$import->id];
        self::assertSame(BotLogImport::DONE, $done->status);
        self::assertSame(34, $done->linesRead);
        self::assertSame(33, $done->botLines);
        self::assertSame('2026-11-01 10:00:00', $done->firstSeen);
        self::assertSame('2026-11-04 11:00:00', $done->lastSeen);
        self::assertSame(['googlebot'], $done->verifiedBots);
        $totals = $store->botTotals($import->id);
        self::assertSame([32, 31], [$totals['googlebot']['hits'], $totals['googlebot']['verified']], 'The request from outside Google\'s ranges is not verified.');
        self::assertNull($totals['bingbot']['verified'], 'Without published ranges, Bingbot stays unverified rather than zero.');
        self::assertStringNotContainsString('203.0.113.9', (string) json_encode($store->hits), 'No IP address is stored.');
        self::assertSame([], glob(dirname($path) . '/*') ?: [], 'The log file is deleted once read.');

        $page = html_entity_decode((string) $s->loggedIn($s->owner)->get('/projects/' . $s->acmeProject->publicId . '/bot-logs')->getBody());
        self::assertStringContainsString('Crawlers in access_log.gz', $page);
        self::assertStringContainsString('<td>96.9%</td></tr>', $page, 'Googlebot\'s verified share.');
        self::assertStringContainsString('/shop?sort=price&page=0', $page);
        self::assertStringContainsString('https://shop.acme.test/best-seller', $page, 'A page with clicks that Googlebot never requested.');
        self::assertStringNotContainsString('<td class="brief-url">https://shop.acme.test/old-page</td>', $page, 'Requested pages are not listed as missed.');
        $bing = (string) $s->loggedIn($s->owner)->get('/projects/' . $s->acmeProject->publicId . '/bot-logs?log=' . $import->publicId . '&bot=bingbot')->getBody();
        self::assertStringContainsString('Details below are for <strong>Bingbot</strong>', $bing);
    }

    public function testRefusesBinaryFilesAndKeepsReadingAcrossJobs(): void
    {
        $s = new Scenario();
        $store = $s->container->get(InMemoryBotLogs::class);
        $service = $s->container->get(BotLogService::class);

        $binary = $service->incomingPath();
        file_put_contents($binary, "\x89PNG\r\n\x1a\n\0\0\0");
        try {
            $service->accept($s->owner, $s->acmeProject, $binary, 'x.png', ClientInfo::cli());
            self::fail('A binary file is refused.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->statusCode());
        }
        self::assertFileDoesNotExist($binary);
        self::assertSame([], $store->imports);

        $path = $service->incomingPath();
        file_put_contents($path, str_repeat('1.2.3.4 - - [01/Nov/2026:00:00:00 +0000] "GET /a HTTP/1.1" 200 1 "-" "Googlebot/2.1"' . "\n", 25000));
        $import = $service->accept($s->owner, $s->acmeProject, $path, 'big.log', ClientInfo::cli());
        $service->run($import->id, static fn(): int => 5);
        $current = $store->byId($import->id) ?? self::fail('Import lost.');
        self::assertSame(BotLogImport::RUNNING, $current->status);
        self::assertSame(20000, $current->linesRead);
        self::assertSame($import->id, $store->scheduled[1]['id']);
        self::assertGreaterThan(0, $store->scheduled[1]['offset']);
        $service->run($import->id, static fn(): int => 600);
        $current = $store->byId($import->id) ?? self::fail('Import lost.');
        self::assertSame(BotLogImport::DONE, $current->status);
        self::assertSame(25000, $current->linesRead);
        self::assertSame(25000, $store->hits[$import->id]['googlebot|/a|200']['hits'], 'Resuming never counts a line twice.');
    }

    public function testPermissions(): void
    {
        $s = new Scenario();
        $service = $s->container->get(BotLogService::class);
        $viewer = $s->loggedIn($s->viewer);
        $base = '/projects/' . $s->acmeProject->publicId . '/bot-logs';
        $page = (string) $viewer->get($base)->getBody();
        self::assertStringContainsString('No logs yet', $page);
        self::assertStringNotContainsString('Upload and analyse', $page);
        self::assertSame(403, $viewer->post($base, [])->getStatusCode());

        $path = $service->incomingPath();
        file_put_contents($path, "log\n");
        try {
            $service->accept($s->viewer, $s->acmeProject, $path, 'a.log', ClientInfo::cli());
            self::fail('A viewer cannot upload logs.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->statusCode());
        }
        self::assertFileDoesNotExist($path, 'A refused upload is deleted.');
    }
}
