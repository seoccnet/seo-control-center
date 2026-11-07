<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Domain\Integrations\Connection;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Integrations\OAuthClientCredentials;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\SyncState;
use SeoControlCenter\Modules\SearchConsole\Google\GoogleAccessTokens;
use SeoControlCenter\Modules\SearchConsole\Google\GoogleTokenSet;
use SeoControlCenter\Modules\SearchConsole\Import\SearchConsoleImporter;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryIntegrations;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchConsoleExtras;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Search Console import (against a simulated Google) and the performance dashboard.
 */
final class SearchPerformanceTest extends TestCase
{
    private const string SITE = 'sc-domain:acme.test';

    private Scenario $s;

    private FakeHttpClient $google;

    private InMemoryIntegrations $store;

    private InMemorySearchPerformance $performance;

    private Connection $connection;

    /** @var list<array<string, mixed>> decoded Search Analytics request bodies */
    private array $queries = [];

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $http = $this->s->container->get(HttpClient::class);
        $store = $this->s->container->get(InMemoryIntegrations::class);
        $performance = $this->s->container->get(InMemorySearchPerformance::class);
        self::assertInstanceOf(FakeHttpClient::class, $http);
        self::assertInstanceOf(InMemoryIntegrations::class, $store);
        self::assertInstanceOf(InMemorySearchPerformance::class, $performance);
        $this->google = $http;
        $this->store = $store;
        $this->performance = $performance;

        $this->store->save('google', new OAuthClientCredentials('123-abc.apps.googleusercontent.com', 'GOCSPX-secret'), 1);
        $this->connection = $this->store->upsert($this->s->acme->id, 'google', 'seo@acme.test', 'webmasters.readonly', $this->s->owner->id);
        $this->store->replaceProperties($this->connection->id, [
            ['site_url' => self::SITE, 'permission_level' => 'siteOwner'],
            ['site_url' => 'https://shop.acme.test/', 'permission_level' => 'siteFullUser'],
        ]);
        $this->s->container->get(GoogleAccessTokens::class)->store($this->connection->id, new GoogleTokenSet('ya29.valid', '1//refresh', 3600, 'webmasters.readonly'));

        $this->google->on('POST', 'https://www.googleapis.com/webmasters/v3/sites/', function (string $url, array $headers, ?string $body): HttpResponse {
            $request = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($request);
            $this->queries[] = $request + ['_url' => $url];

            return new HttpResponse(200, json_encode(['rows' => self::rowsFor($request)], JSON_THROW_ON_ERROR));
        });
    }

    public function testLinkingAProjectQueuesAnImport(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $url = '/projects/' . $this->s->acmeProject->publicId . '/search-console';
        $owner->get($url);
        self::assertSame(303, $owner->post($url, ['property' => $this->connection->publicId . '|' . self::SITE])->getStatusCode());

        self::assertSame([['projectId' => $this->s->acmeProject->id, 'workspaceId' => $this->s->acme->id]], $this->performance->importRequests);
    }

    public function testImporterReadsTotalsThenDetailNewestFirstAndStopsWhenAsked(): void
    {
        $this->link();
        $budget = 2;
        $outcome = $this->importer()->run($this->s->acmeProject->id, static function () use (&$budget): bool {
            return $budget-- > 0;
        });

        self::assertSame(2, $outcome->daysImported);
        self::assertSame(1, $outcome->daysRemaining, 'Only days with impressions are pending; 2026-09-21 has none.');
        self::assertSame(['2026-09-23', '2026-09-22'], array_keys($this->performance->details[$this->s->acmeProject->id]));
        self::assertSame(SyncState::IMPORTING, $this->performance->states[$this->s->acmeProject->id]->status);

        // The totals request covers the retention window with finalized web data.
        $totals = $this->queries[0];
        self::assertSame(['date'], $totals['dimensions']);
        self::assertSame('final', $totals['dataState']);
        self::assertSame('web', $totals['type']);
        self::assertStringContainsString('/sites/sc-domain%3Aacme.test/searchAnalytics/query', (string) $totals['_url']);
        $from = new \DateTimeImmutable((string) $totals['startDate']);
        $to = new \DateTimeImmutable((string) $totals['endDate']);
        self::assertSame(485, (int) $from->diff($to)->days);

        // A second run finishes the backlog without re-reading totals (refreshed < 6 h ago).
        $requestsBefore = count($this->queries);
        $outcome = $this->importer()->run($this->s->acmeProject->id, static fn(): bool => true);
        self::assertSame(0, $outcome->daysRemaining);
        self::assertSame(SyncState::OK, $this->performance->states[$this->s->acmeProject->id]->status);
        $newRequests = array_slice($this->queries, $requestsBefore);
        self::assertNotContains(['date'], array_column($newRequests, 'dimensions'));
        self::assertCount(count(PerformanceDimension::cases()) + 1, $newRequests, 'One day left: one request per breakdown plus joint query/page data.');

        $country = $this->performance->details[$this->s->acmeProject->id]['2026-09-20']['country'][0];
        self::assertSame('deu', $country->key, 'Country codes are normalized to lower case.');
    }

    public function testChangingThePropertyPurgesOldData(): void
    {
        $this->link();
        $this->importer()->run($this->s->acmeProject->id, static fn(): bool => true);
        self::assertSame(0, $this->performance->purges);

        $this->store->link($this->s->acmeProject->id, $this->connection->id, 'https://shop.acme.test/', $this->s->owner->id);
        $this->importer()->run($this->s->acmeProject->id, static fn(): bool => false);

        self::assertSame(1, $this->performance->purges);
        self::assertSame('https://shop.acme.test/', $this->performance->states[$this->s->acmeProject->id]->siteUrl);
    }

    public function testRevokedAccessMarksConnectionAndStopsWithoutRetry(): void
    {
        $this->link();
        $this->google->on('POST', 'https://www.googleapis.com/webmasters/v3/sites/', static fn(): HttpResponse => new HttpResponse(401, '{"error":{"status":"UNAUTHENTICATED","message":"Invalid Credentials"}}'));

        try {
            $this->importer()->run($this->s->acmeProject->id, static fn(): bool => true);
            self::fail('Expected an integration error.');
        } catch (IntegrationException $e) {
            self::assertTrue($e->authorizationLost);
            self::assertFalse($e->retryable);
        }
        self::assertSame('error', $this->store->connections[$this->connection->id]->status);
        self::assertSame(SyncState::ERROR, $this->performance->states[$this->s->acmeProject->id]->status);

        // The next run does not call Google at all.
        $calls = count($this->google->requests);
        self::assertTrue($this->importer()->run($this->s->acmeProject->id, static fn(): bool => true)->skipped);
        self::assertCount($calls, $this->google->requests);
    }

    public function testDashboardShowsImportedDataToProjectReaders(): void
    {
        $this->link();
        $this->importer()->run($this->s->acmeProject->id, static fn(): bool => true);
        $url = '/projects/' . $this->s->acmeProject->publicId . '/performance';

        $html = (string) $this->s->loggedIn($this->s->viewer)->get($url . '?range=7d')->getBody();
        self::assertStringContainsString('Clicks per day', $html);
        self::assertStringContainsString('seo tools', $html);
        self::assertStringContainsString('/pricing', $html);
        self::assertStringContainsString('2026-09-17 – 2026-09-23', $html, 'Range ends on the newest imported day.');
        self::assertStringContainsString('>36<', $html, 'Clicks total of the range.');
        self::assertStringNotContainsString('Import now', $html, 'Viewers cannot start imports.');

        $html = (string) $this->s->loggedIn($this->s->viewer)->get($url . '?range=bogus')->getBody();
        self::assertStringContainsString('?range=28d" aria-current="true" aria-label="Last 28 days">28d', $html, 'Unknown ranges fall back to 28 days.');
    }

    public function testDashboardWithoutDataExplainsWhatHappensNext(): void
    {
        $html = (string) $this->s->loggedIn($this->s->owner)->get('/projects/' . $this->s->acmeProject->publicId . '/performance')->getBody();

        self::assertStringContainsString('Connect Search Console', $html);

        $this->link();
        $html = (string) $this->s->loggedIn($this->s->owner)->get('/projects/' . $this->s->acmeProject->publicId . '/performance')->getBody();
        self::assertStringContainsString('No data yet', $html);
    }

    public function testImportNowNeedsPermissionAndCollapsesIntoTheScheduler(): void
    {
        $this->link();
        $url = '/projects/' . $this->s->acmeProject->publicId . '/performance';

        $viewer = $this->s->loggedIn($this->s->viewer);
        $viewer->get($url);
        self::assertSame(403, $viewer->post($url . '/import')->getStatusCode());
        self::assertSame([], $this->performance->importRequests);

        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($url);
        self::assertSame(303, $owner->post($url . '/import')->getStatusCode());
        self::assertCount(1, $this->performance->importRequests);
    }

    public function testOtherWorkspacesCannotSeeOrImport(): void
    {
        $this->link();
        $this->importer()->run($this->s->acmeProject->id, static fn(): bool => true);
        $url = '/projects/' . $this->s->acmeProject->publicId . '/performance';

        $rival = $this->s->loggedIn($this->s->rivalOwner);
        $response = $rival->get($url);
        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('seo tools', (string) $response->getBody());
        $rival->get('/');
        self::assertSame(404, $rival->post($url . '/import')->getStatusCode());
        self::assertSame([], $this->performance->importRequests);
    }

    public function testImportRequiresPostWithCsrf(): void
    {
        $this->link();
        $url = '/projects/' . $this->s->acmeProject->publicId . '/performance/import';
        $owner = $this->s->loggedIn($this->s->owner);

        self::assertSame(405, $owner->get($url)->getStatusCode());
        $owner->get('/');
        self::assertSame(419, $owner->post($url, [], withCsrf: false)->getStatusCode());
        self::assertSame([], $this->performance->importRequests);
    }

    public function testTodayIsStillCollectingWhenGoogleNamesNoIncompleteDay(): void
    {
        $today = $this->s->container->make(\SeoControlCenter\Core\Support\Clock::class)->now()->setTimezone(new \DateTimeZone('America/Los_Angeles'));
        $yesterday = $today->modify('-1 day')->format('Y-m-d');
        $row = static fn(string $date, int $clicks): array => ['keys' => [$date], 'clicks' => $clicks, 'impressions' => 900, 'position' => 5.0];
        // The live 0.23.0 case: no metadata, and today's partial totals (1 click) looked final.
        $this->routeExtras(['rows' => [$row($yesterday, 28), $row($today->format('Y-m-d'), 1)]]);
        $this->link();
        $this->importer()->run($this->s->acmeProject->id, static fn(): bool => true);
        $extras = $this->s->container->get(InMemorySearchConsoleExtras::class);
        self::assertInstanceOf(InMemorySearchConsoleExtras::class, $extras);
        $days = $extras->freshDays($this->s->acmeProject->id);
        self::assertSame([$yesterday => true, $today->format('Y-m-d') => false], array_combine(array_map(static fn($d): string => $d->date, $days), array_map(static fn($d): bool => $d->complete, $days)));
    }

    public function testTotalsRefreshAlsoImportsPreliminaryDaysOtherSurfacesAndSitemaps(): void
    {
        $this->routeExtras();
        $this->link();
        $this->importer()->run($this->s->acmeProject->id, static fn(): bool => true);
        $project = $this->s->acmeProject;
        $extras = $this->s->container->get(InMemorySearchConsoleExtras::class);
        self::assertInstanceOf(InMemorySearchConsoleExtras::class, $extras);

        self::assertSame(['2026-09-24', '2026-09-25'], array_map(static fn($d): string => $d->date, $extras->freshDays($project->id)), 'Days with final data are not shown as preliminary.');
        self::assertSame(['discover'], array_keys($extras->types[$project->id]), 'Surfaces without rows store nothing.');
        self::assertSame(['https://acme.test/old.xml', 'https://acme.test/sitemap.xml'], array_map(static fn($m): string => $m->url, $extras->sitemaps($project->id)), 'Sitemaps with problems first.');
        $types = array_column(array_filter($this->queries, static fn(array $q): bool => $q['dimensions'] === ['date']), 'type');
        sort($types);
        self::assertSame(['discover', 'googleNews', 'image', 'news', 'video', 'web', 'web'], $types, 'Web totals, web preliminary totals and one request per surface.');

        $html = (string) $this->s->loggedIn($this->s->viewer)->get('/projects/' . $project->publicId . '/performance?range=7d')->getBody();
        self::assertSame([], AccessibilityTest::problems($html, true));
        self::assertStringContainsString('Latest days, not final yet', $html);
        self::assertStringContainsString('(still collecting)', $html);
        self::assertStringContainsString('Other Google surfaces', $html);
        self::assertMatchesRegularExpression('#Discover</td>\s*<td>9</td>#', $html);
        self::assertStringNotContainsString('Image search', $html, 'Surfaces without impressions are left out.');

        $indexing = (string) $this->s->loggedIn($this->s->viewer)->get('/projects/' . $project->publicId . '/indexing')->getBody();
        self::assertStringContainsString('Sitemaps in Search Console', $indexing);
        self::assertStringContainsString('1 with errors or warnings', $indexing);
    }

    public function testFailingExtrasDoNotHoldUpTheImport(): void
    {
        $this->link();
        // No sitemap route: the request fails. The other extras get the default rows.
        $outcome = $this->importer()->run($this->s->acmeProject->id, static fn(): bool => true);

        self::assertSame(0, $outcome->daysRemaining);
        self::assertSame(SyncState::OK, $this->performance->states[$this->s->acmeProject->id]->status);
    }

    public function testBreakdownsArePaginatedUpToTheRowLimit(): void
    {
        $pages = [];
        $this->google->on('POST', 'https://www.googleapis.com/webmasters/v3/sites/', function (string $url, array $headers, ?string $body) use (&$pages): HttpResponse {
            $request = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($request);
            $pages[] = [$request['startRow'], $request['rowLimit']];
            self::assertSame('Bearer ya29.valid', $headers['Authorization']);
            $count = $request['startRow'] === 0 ? (int) $request['rowLimit'] : 10;
            $rows = [];
            for ($i = 0; $i < $count; ++$i) {
                $rows[] = ['keys' => ['q' . ($request['startRow'] + $i)], 'clicks' => 1, 'impressions' => 2, 'position' => 1.5];
            }

            return new HttpResponse(200, json_encode(['rows' => $rows], JSON_THROW_ON_ERROR));
        });

        $provider = $this->s->container->get(\SeoControlCenter\Domain\SearchConsole\SearchPerformanceProvider::class);
        self::assertInstanceOf(\SeoControlCenter\Domain\SearchConsole\SearchPerformanceProvider::class, $provider);
        $rows = $provider->dayBreakdown($this->connection, self::SITE, '2026-09-20', PerformanceDimension::Query, 30000);

        self::assertSame([[0, 25000], [25000, 5000]], $pages, 'Second page only asks for the remainder and ends on a short page.');
        self::assertCount(25010, $rows);
        self::assertSame('q25009', $rows[25009]->key);
    }

    /**
     * Preliminary web totals up to 2026-09-25 (incomplete from that day),
     * Discover traffic, no other surface, and two sitemaps.
     *
     * @param array<string, mixed>|null $fresh another preliminary-totals answer
     */
    private function routeExtras(?array $fresh = null): void
    {
        $this->google->on('POST', 'https://www.googleapis.com/webmasters/v3/sites/', function (string $url, array $headers, ?string $body) use ($fresh): HttpResponse {
            $request = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($request);
            $this->queries[] = $request + ['_url' => $url];
            $day = static fn(string $date, int $clicks, int $impressions): array => ['keys' => [$date], 'clicks' => $clicks, 'impressions' => $impressions, 'position' => 5.0];
            $body = match (true) {
                $request['dataState'] === 'all' => $fresh ?? ['rows' => [$day('2026-09-23', 14, 260), $day('2026-09-24', 11, 230), $day('2026-09-25', 4, 90)], 'metadata' => ['first_incomplete_date' => '2026-09-25']],
                $request['type'] === 'discover' => ['rows' => [$day('2026-09-20', 4, 300), $day('2026-09-22', 5, 280)]],
                $request['type'] === 'web' => ['rows' => self::rowsFor($request)],
                default => [],
            };

            return new HttpResponse(200, json_encode($body, JSON_THROW_ON_ERROR));
        });
        $this->google->json('GET', 'https://www.googleapis.com/webmasters/v3/sites/sc-domain%3Aacme.test/sitemaps', ['sitemap' => [
            ['path' => 'https://acme.test/sitemap.xml', 'lastDownloaded' => '2026-09-24T08:00:00Z', 'errors' => '0', 'warnings' => '0', 'contents' => [['submitted' => '40']]],
            ['path' => 'https://acme.test/old.xml', 'errors' => '3', 'warnings' => '0'],
        ]]);
    }

    private function link(): void
    {
        $this->store->link($this->s->acmeProject->id, $this->connection->id, self::SITE, $this->s->owner->id);
    }

    private function importer(): SearchConsoleImporter
    {
        return $this->s->container->make(SearchConsoleImporter::class);
    }

    /**
     * Simulated Search Analytics: 2026-09-21 has no data (absent), other days
     * have traffic; per-day breakdowns return a few rows.
     *
     * @param array<string, mixed> $request
     * @return list<array<string, mixed>>
     */
    private static function rowsFor(array $request): array
    {
        $dimension = is_array($request['dimensions'] ?? null) ? (string) $request['dimensions'][0] : '';
        $row = static fn(string $key, int $clicks, int $impressions, float $position): array => [
            'keys' => [$key], 'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $impressions > 0 ? $clicks / $impressions : 0, 'position' => $position,
        ];

        return match ($dimension) {
            'date' => [$row('2026-09-20', 10, 200, 8.5), $row('2026-09-22', 12, 240, 7.25), $row('2026-09-23', 14, 260, 6.0)],
            'query' => [$row('seo tools', 5, 50, 3.2), $row('rank tracker', 2, 40, 9.1)],
            'page' => [$row('https://acme.test/pricing', 6, 70, 4.0), $row('https://acme.test/', 3, 90, 7.5)],
            'device' => [$row('DESKTOP', 7, 120, 6.0), $row('MOBILE', 3, 80, 9.0)],
            'country' => [$row('DEU', 6, 100, 5.0), $row('USA', 4, 100, 8.0)],
            default => [],
        };
    }
}
