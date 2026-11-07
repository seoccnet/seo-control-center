<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Modules\Bing\Api\BingWebmasterClient;
use SeoControlCenter\Modules\Bing\BingSiteLink;
use SeoControlCenter\Modules\Bing\Import\BingImporter;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryBing;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Bing Webmaster Tools: API key, project link, import (against a simulated
 * Bing API), the Bing performance view, CSV export and tenant isolation.
 */
final class BingHttpTest extends TestCase
{
    private const string KEY = 'abcdef0123456789abcdef0123456789';
    private const string SITE = 'https://shop.acme.test/';

    private Scenario $s;

    private FakeHttpClient $http;

    private InMemoryBing $bing;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-09-30 12:00:00 UTC'));
        $this->s->container->instance(Clock::class, $this->clock);
        $http = $this->s->container->get(HttpClient::class);
        $bing = $this->s->container->get(InMemoryBing::class);
        self::assertInstanceOf(FakeHttpClient::class, $http);
        self::assertInstanceOf(InMemoryBing::class, $bing);
        $this->http = $http;
        $this->bing = $bing;
        $this->http->on('GET', BingWebmasterClient::BASE . 'GetUserSites', fn(string $url): HttpResponse => str_contains($url, 'apikey=' . self::KEY)
            ? new HttpResponse(200, json_encode(['d' => [['Url' => self::SITE, 'IsVerified' => true], ['Url' => 'https://pending.test/', 'IsVerified' => false]]], JSON_THROW_ON_ERROR))
            : new HttpResponse(400, '{"ErrorCode":3,"Message":"InvalidApiKey"}'));
    }

    public function testOwnerConnectsWithAnApiKey(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get('/integrations/bing');

        self::assertSame(422, $owner->post('/integrations/bing', ['api_key' => 'short'])->getStatusCode());
        $rejected = $owner->post('/integrations/bing', ['api_key' => 'ffffffffffffffffffffffffffffffff']);
        self::assertSame(422, $rejected->getStatusCode());
        self::assertStringContainsString('Bing rejected this API key.', (string) $rejected->getBody());

        self::assertSame(303, $owner->post('/integrations/bing', ['api_key' => ' ' . self::KEY . ' '])->getStatusCode());
        $page = (string) $owner->get('/integrations/bing')->getBody();

        self::assertStringContainsString('Bing connected with 1 verified site.', $page);
        self::assertStringContainsString(self::SITE, $page);
        self::assertStringNotContainsString('pending.test', $page, 'Unverified sites are not offered.');
        self::assertStringNotContainsString(self::KEY, $page, 'The key is never shown again.');
        self::assertSame(self::KEY, $this->bing->apiKey($this->s->acme->id));
        self::assertContains('integration.bing.connected', $this->s->audit()->actions());
    }

    public function testMembersCannotChangeTheConnection(): void
    {
        $member = $this->s->loggedIn($this->s->member);
        $page = (string) $member->get('/integrations/bing')->getBody();
        self::assertStringContainsString('A workspace owner or admin can connect', $page);
        self::assertSame(403, $member->post('/integrations/bing', ['api_key' => self::KEY])->getStatusCode());
        self::assertNull($this->bing->find($this->s->acme->id));
    }

    public function testLinkImportShowAndExport(): void
    {
        $project = $this->s->acmeProject;
        $base = '/projects/' . $project->publicId;
        $this->bing->save($this->s->acme->id, self::KEY, [self::SITE], $this->s->owner->id);
        $owner = $this->s->loggedIn($this->s->owner);

        self::assertStringContainsString('Link a Bing site', (string) $owner->get($base . '/performance/bing')->getBody());
        $owner->get($base . '/bing');
        self::assertSame(422, $owner->post($base . '/bing', ['site' => 'https://evil.test/'])->getStatusCode());
        self::assertSame(303, $owner->post($base . '/bing', ['site' => self::SITE])->getStatusCode());
        self::assertSame([['projectId' => $project->id, 'workspaceId' => $this->s->acme->id]], $this->bing->importRequests);

        $this->routeBingData();
        $this->s->container->get(BingImporter::class)->run($project->id);
        self::assertSame(BingSiteLink::OK, $this->bing->link($project->id)?->status);

        $html = (string) $this->s->loggedIn($this->s->viewer)->get($base . '/performance/bing')->getBody();
        self::assertStringContainsString('Clicks per day', $html);
        self::assertMatchesRegularExpression('#Top queries.*red shoes.*blue shoes#s', $html, 'By clicks.');
        self::assertStringContainsString('/shoes', $html);
        self::assertStringContainsString('aria-current="page">Bing', $html, 'The source switch marks Bing.');
        self::assertStringNotContainsString('Average position</h2>', $html, 'Bing has no daily position.');
        self::assertStringContainsString('>12<', $html, 'Total clicks of the two days.');
        self::assertMatchesRegularExpression('#Pages in Bing\'s index</h2><p class="stat-value">310</p><p class="stat-delta">\+10#', $html, 'Latest index count and its change in the period.');
        self::assertStringContainsString('25 inbound links to 2 pages', $html);
        self::assertMatchesRegularExpression('#Most linked pages.*/shoes.*>20<#s', $html);

        $csv = $owner->get($base . '/performance/bing/export?dimension=query&range=28d');
        self::assertSame(200, $csv->getStatusCode());
        self::assertSame('attachment; filename="acme-shop-bing-queries-28d-2026-09-27.csv"', $csv->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString("\"red shoes\",8,200,4,3.5\r\n", (string) $csv->getBody());

        self::assertStringContainsString('aria-current="page">Google', (string) $owner->get($base . '/performance')->getBody());
        self::assertSame(303, $owner->post($base . '/performance/bing/import')->getStatusCode());
        self::assertCount(2, $this->bing->importRequests);
    }

    public function testDashboardAddsBingAndTheOverviewShowsItSeparately(): void
    {
        $project = $this->s->acmeProject;
        $performance = $this->s->container->get(InMemorySearchPerformance::class);
        self::assertInstanceOf(InMemorySearchPerformance::class, $performance);
        $performance->upsertDailyTotals($project->id, [new DailyMetrics('2026-09-27', new Metrics(100, 1000, 7.5))]);
        $owner = $this->s->loggedIn($this->s->owner);

        $googleOnly = (string) $owner->get('/')->getBody();
        self::assertStringNotContainsString('stat-split', $googleOnly, 'Without Bing the card looks as before.');

        $this->bing->save($this->s->acme->id, self::KEY, [self::SITE], $this->s->owner->id);
        $this->bing->saveLink($project->id, $this->s->acme->id, self::SITE, $this->s->owner->id);
        $this->routeBingData();
        $this->s->container->get(BingImporter::class)->run($project->id);

        $dashboard = (string) $owner->get('/')->getBody();
        self::assertStringContainsString('data-clicks="112"', $dashboard, 'Google and Bing clicks add up.');
        self::assertStringContainsString('<dt>Impressions</dt><dd>1,240</dd>', $dashboard);
        self::assertStringContainsString('Google + Bing', $dashboard);
        self::assertStringContainsString('<dt>Google position</dt><dd>7.5</dd>', $dashboard, 'Position stays Google\'s.');

        $overview = (string) $owner->get('/projects/' . $project->publicId)->getBody();
        self::assertStringContainsString('2026-08-31 – 2026-09-27', $overview, 'One combined view over the Search Console range.');
        self::assertMatchesRegularExpression('#<p class="stat-value">112</p>\s*<p class="stat-note">Google \+ Bing</p>#', $overview);
        self::assertStringContainsString('Clicks: Google 100 · Bing 12', $overview);
        self::assertStringContainsString('Google · impression-weighted', $overview);
        self::assertStringContainsString('Performance prognosis', $overview);
        self::assertStringContainsString('Bing remains in the search summary; it is excluded here', $overview);
        $doc = \Dom\HTMLDocument::createFromString($overview, LIBXML_NOERROR);
        $history = json_decode((string) $doc->querySelector('[data-prognosis]')?->getAttribute('data-history'), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $history);
        self::assertSame(100, $history[0]['clicks'], 'Forecast history must not invent partial Google/Bing totals.');
        self::assertStringNotContainsString('bing-summary', $overview, 'No separate Bing row next to the combined view.');
        self::assertStringContainsString('aria-current="page">Bing', (string) $owner->get('/projects/' . $project->publicId . '/performance/bing')->getBody(), 'Performance keeps the engines apart.');
    }

    public function testOpportunitiesCountBingDemandToo(): void
    {
        $project = $this->s->acmeProject;
        $rankings = $this->s->container->get(\SeoControlCenter\Tests\Support\Fakes\InMemoryRankings::class);
        $performance = $this->s->container->get(InMemorySearchPerformance::class);
        self::assertInstanceOf(\SeoControlCenter\Tests\Support\Fakes\InMemoryRankings::class, $rankings);
        self::assertInstanceOf(InMemorySearchPerformance::class, $performance);
        $rankings->projects[$project->id] = ['workspace_id' => $this->s->acme->id, 'domain' => 'shop.acme.test'];
        // 150 Google impressions at position 2 with a weak CTR: below the rule's 200-impression floor on its own.
        $performance->upsertDailyTotals($project->id, [new DailyMetrics('2026-09-26', new Metrics(4, 2000, 8.0))]);
        $performance->replaceDay($project->id, '2026-09-26', ['query' => [new \SeoControlCenter\Domain\SearchConsole\DimensionRow('weak snippet', new Metrics(3, 150, 2.0))], 'page' => []]);
        $owner = $this->s->loggedIn($this->s->owner);
        $url = '/projects/' . $project->publicId . '/opportunities';

        $owner->get($url);
        $owner->post($url . '/analyze');
        self::assertStringNotContainsString('weak snippet', (string) $owner->get($url)->getBody(), 'Google alone is below the threshold.');

        $this->bing->saveLink($project->id, $this->s->acme->id, self::SITE, $this->s->owner->id);
        $this->bing->replaceData($project->id, [new DailyMetrics('2026-09-26', new Metrics(0, 100, 0.0))], [
            new \SeoControlCenter\Modules\Bing\BingWeeklyRow('2026-09-26', \SeoControlCenter\Domain\SearchConsole\PerformanceDimension::Query, new \SeoControlCenter\Domain\SearchConsole\DimensionRow('weak snippet', new Metrics(0, 100, 2.0))),
        ]);
        $owner->get($url);
        $owner->post($url . '/analyze');
        $html = (string) $owner->get($url)->getBody();

        self::assertStringContainsString('Improve the search snippet for “weak snippet”', $html, 'Google + Bing impressions together pass it.');
        self::assertStringContainsString('Google + Bing', $html, 'The evidence names both engines.');
    }

    public function testOldDataIsNotPassedOffAsRecent(): void
    {
        $project = $this->s->acmeProject;
        $base = '/projects/' . $project->publicId;
        $this->bing->save($this->s->acme->id, self::KEY, [self::SITE], $this->s->owner->id);
        $this->bing->saveLink($project->id, $this->s->acme->id, self::SITE, $this->s->owner->id);
        $this->routeBingData();
        $this->s->container->get(BingImporter::class)->run($project->id);
        $this->clock->advance('+5 weeks');

        $html = (string) $this->s->loggedIn($this->s->viewer)->get($base . '/performance/bing')->getBody();

        self::assertStringContainsString("Bing's newest data for this site is from 2026-09-27", $html);
        self::assertStringContainsString('2026-10-05 – 2026-11-01', $html, 'The range ends near today, not on the old data.');
        self::assertStringContainsString('No weekly data for this range yet.', $html);
        $csv = $this->s->loggedIn($this->s->owner)->get($base . '/performance/bing/export?dimension=query');
        self::assertSame('attachment; filename="acme-shop-bing-queries-28d-2026-11-01.csv"', $csv->getHeaderLine('Content-Disposition'));
    }

    public function testRejectedKeyStopsTheImportAndIsShown(): void
    {
        $project = $this->s->acmeProject;
        $this->bing->save($this->s->acme->id, 'ffffffffffffffffffffffffffffffff', [self::SITE], $this->s->owner->id);
        $this->bing->saveLink($project->id, $this->s->acme->id, self::SITE, $this->s->owner->id);
        $this->http->on('GET', BingWebmasterClient::BASE . 'GetRankAndTrafficStats', static fn(): HttpResponse => new HttpResponse(400, '{"ErrorCode":3,"Message":"InvalidApiKey"}'));

        $this->s->container->get(BingImporter::class)->run($project->id);

        self::assertSame(BingSiteLink::ERROR, $this->bing->link($project->id)?->status);
        self::assertFalse($this->bing->find($this->s->acme->id)?->isActive());
        self::assertStringContainsString('Bing rejected the API key', (string) $this->s->loggedIn($this->s->owner)->get('/projects/' . $project->publicId . '/performance/bing')->getBody());
    }

    public function testOtherWorkspacesGetNotFound(): void
    {
        $this->bing->save($this->s->acme->id, self::KEY, [self::SITE], $this->s->owner->id);
        $this->bing->saveLink($this->s->acmeProject->id, $this->s->acme->id, self::SITE, $this->s->owner->id);
        $rival = $this->s->loggedIn($this->s->rivalOwner);
        $base = '/projects/' . $this->s->acmeProject->publicId;

        foreach (['/bing', '/performance/bing', '/performance/bing/export'] as $path) {
            self::assertSame(404, $rival->get($base . $path)->getStatusCode(), $path);
        }
        $rival->get('/integrations/bing');
        self::assertSame(404, $rival->post($base . '/bing/unlink')->getStatusCode());
        self::assertNotNull($this->bing->link($this->s->acmeProject->id));
        self::assertStringNotContainsString(self::SITE, (string) $rival->get('/integrations/bing')->getBody(), "The rival sees only its own workspace's connection.");
    }

    private function routeBingData(): void
    {
        $date = static fn(string $day): string => '/Date(' . (strtotime($day . ' 07:00:00 UTC') * 1000) . '-0700)/';
        $this->http->json('GET', BingWebmasterClient::BASE . 'GetRankAndTrafficStats', ['d' => [
            ['Date' => $date('2026-09-26'), 'Clicks' => 5, 'Impressions' => 100],
            ['Date' => $date('2026-09-27'), 'Clicks' => 7, 'Impressions' => 140],
        ]]);
        $this->http->json('GET', BingWebmasterClient::BASE . 'GetQueryStats', ['d' => [
            ['Date' => $date('2026-09-19'), 'Query' => 'red shoes', 'Clicks' => 3, 'Impressions' => 50, 'AvgImpressionPosition' => 5],
            ['Date' => $date('2026-09-26'), 'Query' => 'red shoes', 'Clicks' => 5, 'Impressions' => 150, 'AvgImpressionPosition' => 3],
            ['Date' => $date('2026-09-26'), 'Query' => 'blue shoes', 'Clicks' => 1, 'Impressions' => 40, 'AvgImpressionPosition' => 9],
        ]]);
        $this->http->json('GET', BingWebmasterClient::BASE . 'GetPageStats', ['d' => [
            ['Date' => $date('2026-09-26'), 'Query' => 'https://shop.acme.test/shoes', 'Clicks' => 6, 'Impressions' => 190, 'AvgImpressionPosition' => 3],
        ]]);
        $this->http->json('GET', BingWebmasterClient::BASE . 'GetCrawlStats', ['d' => [
            ['Date' => $date('2026-09-26'), 'CrawledPages' => 40, 'CrawlErrors' => 2, 'InIndex' => 300, 'Code2xx' => 38, 'Code4xx' => 2],
            ['Date' => $date('2026-09-27'), 'CrawledPages' => 35, 'CrawlErrors' => 0, 'InIndex' => 310, 'Code2xx' => 35],
        ]]);
        $this->http->json('GET', BingWebmasterClient::BASE . 'GetLinkCounts', ['d' => ['TotalPages' => 1, 'Links' => [
            ['Count' => 5, 'Url' => 'https://shop.acme.test/'],
            ['Count' => 20, 'Url' => 'https://shop.acme.test/shoes'],
        ]]]);
    }

    public function testCrawlDataFailureKeepsThePerformanceImport(): void
    {
        $project = $this->s->acmeProject;
        $this->bing->save($this->s->acme->id, self::KEY, [self::SITE], $this->s->owner->id);
        $this->bing->saveLink($project->id, $this->s->acme->id, self::SITE, $this->s->owner->id);
        $this->routeBingData();
        $this->http->on('GET', BingWebmasterClient::BASE . 'GetLinkCounts', static fn(): HttpResponse => new HttpResponse(503, ''));

        $this->s->container->get(BingImporter::class)->run($project->id);

        self::assertSame(BingSiteLink::OK, $this->bing->link($project->id)?->status);
        self::assertCount(2, $this->bing->days[$project->id]);
        self::assertSame([], $this->bing->crawl[$project->id] ?? [], 'Crawl data is stored together with the link counts, or not at all.');
    }
}
