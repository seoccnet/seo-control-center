<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Modules\Pages\PageQuery;
use SeoControlCenter\Modules\Pages\PageSummary;
use SeoControlCenter\Tests\Support\Fakes\InMemoryPages;
use SeoControlCenter\Tests\Support\Scenario;

final class PagesHttpTest extends TestCase
{
    public function testFullExportIncludesMatchesBeyondPaginationAndRejectsOversizedSelections(): void
    {
        $s = new Scenario();
        $pages = $s->container->make(InMemoryPages::class);
        for ($i = 0; $i < 130; ++$i) {
            $pages->pages[$s->acmeProject->id][] = new PageSummary('https://shop.acme.test/export/' . sprintf('%03d', $i), new Metrics($i, 100, 5), null);
        }
        $pages->pages[$s->acmeProject->id][] = new PageSummary('https://shop.acme.test/other', null, null);
        $pages->pages[$s->rivalProject->id][] = new PageSummary('https://rival.test/export/secret', null, null);
        $browser = $s->loggedIn($s->viewer);
        $base = '/projects/' . $s->acmeProject->publicId . '/pages';
        $response = $browser->get($base . '?q=export&sort=google_clicks&page=2&per_page=25&format=csv&scope=all');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('pages-all-', $response->getHeaderLine('Content-Disposition'));
        $csv = (string) $response->getBody();
        self::assertCount(131, explode("\r\n", rtrim($csv, "\r\n")));
        self::assertStringContainsString('/000,0,,100,', $csv);
        self::assertLessThan(strpos($csv, '/000'), strpos($csv, '/129'));
        self::assertStringNotContainsString('/other', $csv);
        self::assertStringNotContainsString('rival.test', $csv);
        self::assertSame(404, $browser->get('/projects/' . $s->rivalProject->publicId . '/pages?format=csv&scope=all')->getStatusCode());
        self::assertCount(1, explode("\r\n", rtrim((string) $browser->get($base . '?q=missing&format=csv&scope=all')->getBody(), "\r\n")));
        self::assertStringContainsString('scope=all', (string) $browser->get($base)->getBody());
        $pages->pages[$s->acmeProject->id] = array_fill(0, 10000, new PageSummary('https://shop.acme.test/large', null, null));
        self::assertSame(200, $browser->get($base . '?format=csv&scope=all')->getStatusCode());
        $pages->pages[$s->acmeProject->id][] = new PageSummary('https://shop.acme.test/narrow', null, null);
        $oversized = $browser->get($base . '?format=csv&scope=all');
        self::assertSame(422, $oversized->getStatusCode());
        self::assertStringContainsString('Use Prepare CSV export', (string) $oversized->getBody());
        self::assertSame('', $oversized->getHeaderLine('Content-Disposition'));
        self::assertSame(200, $browser->get($base . '?q=narrow&format=csv&scope=all')->getStatusCode());
    }

    public function testCsvExportsOnlyTheAuthorizedFilteredPageAndPreservesMissingMetrics(): void
    {
        $s = new Scenario();
        $pages = $s->container->make(InMemoryPages::class);
        for ($i = 0; $i < 30; ++$i) {
            $pages->pages[$s->acmeProject->id][] = new PageSummary('https://shop.acme.test/export/' . sprintf('%03d', $i), new Metrics($i, 100, 5), null);
        }
        $pages->pages[$s->acmeProject->id][] = new PageSummary('https://shop.acme.test/other', null, null);
        $browser = $s->loggedIn($s->viewer);
        $base = '/projects/' . $s->acmeProject->publicId . '/pages';
        $response = $browser->get($base . '?q=export&sort=google_clicks&per_page=25&page=2&format=csv');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('pages-page-2-', $response->getHeaderLine('Content-Disposition'));
        $csv = (string) $response->getBody();
        self::assertStringStartsWith("\u{FEFF}", $csv);
        self::assertStringContainsString('https://shop.acme.test/export/004,4,,100,', $csv);
        self::assertStringNotContainsString('/029', $csv);
        self::assertStringNotContainsString('/other', $csv);
        self::assertLessThan(strpos($csv, '/003'), strpos($csv, '/004'));
        self::assertCount(6, explode("\r\n", rtrim($csv, "\r\n")));
        self::assertStringContainsString('format=csv', (string) $browser->get($base)->getBody());
        self::assertSame(404, $browser->get('/projects/' . $s->rivalProject->publicId . '/pages?format=csv')->getStatusCode());
        $empty = (string) $browser->get($base . '?q=missing&format=csv')->getBody();
        self::assertCount(1, explode("\r\n", rtrim($empty, "\r\n")));
    }

    public function testCatalogFiltersAndSortsBeyondTheFirstPage(): void
    {
        $s = new Scenario();
        $pages = $s->container->make(InMemoryPages::class);
        for ($i = 0; $i < 110; ++$i) {
            $pages->pages[$s->acmeProject->id][] = new PageSummary('https://shop.acme.test/' . sprintf('%03d', $i), new Metrics($i, $i * 10, 5), null);
        }
        $pages->pages[$s->acmeProject->id][] = new PageSummary('https://shop.acme.test/offer_100%', null, null);
        $pages->pages[$s->rivalProject->id][] = new PageSummary('https://rival.test/secret', new Metrics(999, 9999, 1), null);
        $browser = $s->loggedIn($s->viewer);
        $base = '/projects/' . $s->acmeProject->publicId . '/pages';
        $filtered = (string) $browser->get($base . '?q=109&page=99')->getBody();
        self::assertStringContainsString('https://shop.acme.test/109', $filtered);
        self::assertStringNotContainsString('https://shop.acme.test/000', $filtered);
        $sorted = (string) $browser->get($base . '?sort=google_clicks&per_page=25')->getBody();
        self::assertStringContainsString('https://shop.acme.test/109', $sorted);
        self::assertStringNotContainsString('https://shop.acme.test/000', $sorted);
        self::assertStringNotContainsString('rival.test/secret', $sorted);
        self::assertStringContainsString('per_page=25', $sorted);
        self::assertStringContainsString('https://shop.acme.test/offer_100%', (string) $browser->get($base . '?q=' . rawurlencode('_100%'))->getBody());
        self::assertStringContainsString('No URLs match', (string) $browser->get($base . '?q=missing')->getBody());
        self::assertSame(200, $browser->get($base . '?sort=invalid&per_page=999')->getStatusCode());
    }

    public function testPageDetailsShowStoredEvidenceAndEscapeProviderText(): void
    {
        $s = new Scenario();
        $pages = $s->container->make(InMemoryPages::class);
        $url = 'https://shop.acme.test/seo?x=1&y=2';
        $pages->pages[$s->acmeProject->id] = [new PageSummary($url, new Metrics(12, 200, 6), new Metrics(3, 30, 5))];
        $pages->queryRows[$s->acmeProject->id] = [new PageQuery('<script>alert(1)</script>', $url, new Metrics(4, 80, 6))];
        $browser = $s->loggedIn($s->viewer);
        $base = '/projects/' . $s->acmeProject->publicId . '/pages';
        $list = $browser->get($base);
        self::assertSame(200, $list->getStatusCode());
        self::assertStringContainsString(rawurlencode($url), (string) $list->getBody());
        $details = $browser->get($base . '/details?url=' . rawurlencode($url));
        self::assertSame(200, $details->getStatusCode());
        self::assertSame('no-store', $details->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('What to improve', (string) $details->getBody());
        self::assertStringContainsString('Improve rankings within reach', (string) $details->getBody());
        self::assertStringContainsString('href="#page-search"', (string) $details->getBody());
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', (string) $details->getBody());
        self::assertStringNotContainsString('<script>alert(1)</script>', (string) $details->getBody());
        self::assertSame(404, $browser->get($base . '/details?url=' . rawurlencode('https://shop.acme.test/unknown'))->getStatusCode());
        self::assertSame(400, $browser->get($base . '/details?url=javascript:alert(1)')->getStatusCode());
    }

    public function testTrafficComparisonRequiresBothCompletePageImportPeriods(): void
    {
        $s = new Scenario();
        $pages = $s->container->make(InMemoryPages::class);
        $search = $s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance::class);
        $url = 'https://shop.acme.test/traffic';
        $range = \SeoControlCenter\Domain\Shared\DateRange::endingOn('2026-09-29', 28);
        $search->daily[$s->acmeProject->id]['2026-09-29'] = new Metrics(100, 1000, 10);
        $pages->pages[$s->acmeProject->id] = [new PageSummary($url, new Metrics(50, 1000, 12), null)];
        $pages->periodPages[$s->acmeProject->id][$range->previous()->fromString()] = [new PageSummary($url, new Metrics(100, 2000, 8), null)];
        $pages->importDays[$s->acmeProject->id] = [...$range->eachDay(), ...$range->previous()->eachDay()];
        $service = $s->container->make(\SeoControlCenter\Modules\Pages\PageService::class);
        $details = $service->details($s->acmeProject, $url);
        self::assertTrue($details['traffic_comparison']['available']);
        self::assertSame('traffic_decline', $details['improvements'][0]['id']);
        $browser = $s->loggedIn($s->viewer);
        $path = '/projects/' . $s->acmeProject->publicId . '/pages/details?url=' . rawurlencode($url);
        self::assertStringContainsString('down 50.0%', (string) $browser->get($path)->getBody());
        array_pop($pages->importDays[$s->acmeProject->id]);
        $incomplete = $service->details($s->acmeProject, $url);
        self::assertFalse($incomplete['traffic_comparison']['available']);
        self::assertSame([], $incomplete['improvements']);
        self::assertStringContainsString('Traffic trend unavailable', (string) $browser->get($path)->getBody());
    }

    public function testEveryKnownUrlCanBeReachedWithPagination(): void
    {
        $s = new Scenario();
        $pages = $s->container->make(InMemoryPages::class);
        for ($i = 0; $i < 55; ++$i) {
            $pages->pages[$s->acmeProject->id][] = new PageSummary('https://shop.acme.test/' . sprintf('%03d', $i), null, null);
        }
        $browser = $s->loggedIn($s->owner);
        $base = '/projects/' . $s->acmeProject->publicId . '/pages';
        $first = (string) $browser->get($base)->getBody();
        self::assertStringContainsString('Next', $first);
        self::assertStringNotContainsString('https://shop.acme.test/054', $first);
        self::assertStringContainsString('https://shop.acme.test/054', (string) $browser->get($base . '?page=2')->getBody());
    }

    public function testPageDetailsShowClickPotentialCompetitorsAndChangeResults(): void
    {
        $s = new Scenario();
        $pages = $s->container->make(InMemoryPages::class);
        $url = 'https://shop.acme.test/guide';
        $pages->pages[$s->acmeProject->id] = [new PageSummary($url, new Metrics(12, 2000, 8), null)];
        $pages->queryRows[$s->acmeProject->id] = [new PageQuery('seed <guide>', $url, new Metrics(10, 1000, 8.2))];
        $browser = $s->loggedIn($s->viewer);
        $path = '/projects/' . $s->acmeProject->publicId . '/pages/details?url=' . rawurlencode($url);

        $body = (string) $browser->get($path)->getBody();
        self::assertStringContainsString('Worth reaching the top 3', $body);
        self::assertMatchesRegularExpression('#<td>seed &lt;guide&gt;</td><td class="num">8\.2</td>#', $body);
        self::assertStringContainsString('No competitor analysis from the last 180 days included this page', $body);
        self::assertStringContainsString('No change to this page was recorded in the last year', $body);

        $analyses = $s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemoryAnalyses::class);
        $cluster = \SeoControlCenter\Core\Support\Uuid::v7();
        $id = $analyses->queue($s->acmeProject, $cluster, 'Seeds', 'seed guide', ['seed guide'], 100.0, 'live', 2840, 'en', $s->owner->id, '2026-09-28 00:00:00');
        $structure = static fn(string $u, int $words): array => (new \SeoControlCenter\Domain\Comparison\PageStructure($u, 'ok', wordCount: $words))->toArray();
        $analyses->complete($id, [
            ['position' => 1, 'url' => 'https://rival.test/a', 'domain' => 'rival.test', 'title' => null, 'type' => 'guide', 'confidence' => 'high', 'signals' => [], 'own' => false, 'fetched' => true],
            ['position' => 8, 'url' => $url, 'domain' => 'shop.acme.test', 'title' => null, 'type' => 'guide', 'confidence' => 'high', 'signals' => [], 'own' => true, 'fetched' => true],
        ], $url, 8, ['https://rival.test/a' => $structure('https://rival.test/a', 3000), $url => $structure($url, 600)], [], 0.1, (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'));

        $body = (string) $browser->get($path)->getBody();
        self::assertStringContainsString('this page ranked #8, compared with 1 page above it (#1 rival.test)', $body);
        self::assertMatchesRegularExpression('#<td>Words</td><td class="num">600</td><td class="num">3,000</td><td><span class="badge badge-warning">Behind</span>#', $body);
        self::assertStringContainsString('href="/projects/' . $s->acmeProject->publicId . '/keywords/topics/' . $cluster . '/analysis"', $body);
        self::assertStringContainsString('Close the gap with the pages above it', $body);

        $details = $s->container->make(\SeoControlCenter\Modules\Pages\PageService::class)->details($s->acmeProject, $url);
        self::assertIsArray($details['change_results']);
        self::assertJson(json_encode($details, JSON_THROW_ON_ERROR), 'the MCP page_details tool returns these details as JSON');
    }
}
