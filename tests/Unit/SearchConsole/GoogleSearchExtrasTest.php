<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\SearchConsole;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Domain\Integrations\Connection;
use SeoControlCenter\Domain\SearchConsole\SearchType;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\SearchConsole\Google\GoogleAccessTokens;
use SeoControlCenter\Modules\SearchConsole\Google\GoogleSearchConsoleApi;
use SeoControlCenter\Modules\SearchConsole\Google\GoogleTokenSet;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryIntegrations;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Preliminary data, other search types and sitemaps against responses in
 * Google's documented format.
 */
final class GoogleSearchExtrasTest extends TestCase
{
    private const string QUERY = GoogleSearchConsoleApi::BASE . '/sites/sc-domain%3Aacme.test/searchAnalytics/query';

    private FakeHttpClient $http;

    private GoogleSearchConsoleApi $api;

    private Connection $connection;

    protected function setUp(): void
    {
        $s = new Scenario();
        $tokens = $s->container->make(GoogleAccessTokens::class);
        $this->connection = $s->container->make(InMemoryIntegrations::class)->upsert($s->acme->id, 'google', 'google@acme.test', '', $s->owner->id);
        $tokens->store($this->connection->id, new GoogleTokenSet('test-access', 'test-refresh', 3600, ''));
        $this->http = new FakeHttpClient();
        $this->api = new GoogleSearchConsoleApi($this->http, $tokens);
    }

    public function testFreshTotalsAskForAllDataAndMarkIncompleteDays(): void
    {
        $this->http->on('POST', self::QUERY, static function (string $url, array $headers, ?string $body): HttpResponse {
            $request = json_decode((string) $body, true);
            self::assertSame('all', $request['dataState']);
            self::assertSame('web', $request['type']);
            self::assertSame(['date'], $request['dimensions']);

            return new HttpResponse(200, json_encode(['rows' => [
                ['keys' => ['2026-10-11'], 'clicks' => 3, 'impressions' => 90, 'position' => 6.2],
                ['keys' => ['2026-10-09'], 'clicks' => 10, 'impressions' => 300, 'position' => 5.0],
                ['keys' => ['2026-10-10'], 'clicks' => 8, 'impressions' => 250, 'position' => 5.5],
            ], 'metadata' => ['first_incomplete_date' => '2026-10-10']], JSON_THROW_ON_ERROR));
        });

        $days = $this->api->freshDailyTotals($this->connection, 'sc-domain:acme.test', DateRange::endingOn('2026-10-11', 10));

        self::assertSame(['2026-10-09', '2026-10-10', '2026-10-11'], array_map(static fn($d): string => $d->date, $days));
        self::assertSame([true, false, false], array_map(static fn($d): bool => $d->complete, $days));
        self::assertSame(10, $days[0]->metrics->clicks);
    }

    public function testSearchTypesUseTheirTypeAndDropPositionWhereGoogleHasNone(): void
    {
        $types = [];
        $this->http->on('POST', self::QUERY, static function (string $url, array $headers, ?string $body) use (&$types): HttpResponse {
            $request = json_decode((string) $body, true);
            $types[] = $request['type'];

            return new HttpResponse(200, json_encode(['rows' => [['keys' => ['2026-10-01'], 'clicks' => 4, 'impressions' => 40, 'position' => 2.5]]], JSON_THROW_ON_ERROR));
        });

        $discover = $this->api->searchTypeDailyTotals($this->connection, 'sc-domain:acme.test', SearchType::Discover, DateRange::endingOn('2026-10-01', 7));
        $image = $this->api->searchTypeDailyTotals($this->connection, 'sc-domain:acme.test', SearchType::Image, DateRange::endingOn('2026-10-01', 7));

        self::assertSame(['discover', 'image'], $types);
        self::assertSame(0.0, $discover[0]->metrics->position);
        self::assertSame(2.5, $image[0]->metrics->position);
        self::assertSame(4, $discover[0]->metrics->clicks);
    }

    public function testSitemapsReadStringCountsAndUtcTimes(): void
    {
        $this->http->json('GET', GoogleSearchConsoleApi::BASE . '/sites/sc-domain%3Aacme.test/sitemaps', ['sitemap' => [
            ['path' => 'https://acme.test/sitemap.xml', 'isSitemapsIndex' => true, 'isPending' => false, 'lastSubmitted' => '2026-10-01T10:00:00.000Z', 'lastDownloaded' => '2026-10-10T12:00:00+02:00', 'warnings' => '0', 'errors' => '0'],
            ['path' => 'https://acme.test/posts.xml', 'isPending' => true, 'warnings' => '2', 'errors' => '1', 'contents' => [['type' => 'web', 'submitted' => '120'], ['type' => 'image', 'submitted' => '30']]],
            ['path' => 'javascript:alert(1)', 'errors' => '9'],
        ]]);

        $sitemaps = $this->api->sitemaps($this->connection, 'sc-domain:acme.test');

        self::assertCount(2, $sitemaps, 'Paths that are not HTTP(S) URLs are dropped.');
        self::assertTrue($sitemaps[0]->isIndex);
        self::assertSame('2026-10-10 10:00:00', $sitemaps[0]->lastDownloaded);
        self::assertFalse($sitemaps[0]->hasProblems());
        self::assertSame([1, 2, 150, true], [$sitemaps[1]->errors, $sitemaps[1]->warnings, $sitemaps[1]->submittedUrls, $sitemaps[1]->isPending]);
        self::assertNull($sitemaps[1]->lastDownloaded);
    }
}
