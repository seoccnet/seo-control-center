<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\DataForSEO;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Modules\DataForSEO\Adapters\DataForSeoKeywordMetrics;
use SeoControlCenter\Modules\DataForSEO\Adapters\DataForSeoSerp;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\DataForSeoCredentials;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDataForSeo;

/**
 * DataForSEO responses normalized into the provider-independent model.
 */
final class DataForSeoAdaptersTest extends TestCase
{
    private const int WORKSPACE = 3;

    private FakeHttpClient $http;

    private InMemoryDataForSeo $store;

    private DataForSeoClient $client;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-09-26 12:00:00', new \DateTimeZone('UTC')));
        $this->store = new InMemoryDataForSeo($this->clock);
        $this->store->save(self::WORKSPACE, new DataForSeoCredentials('api@acme.test', 'secret'), 10.0, 1);
        $this->client = new DataForSeoClient($this->http, $this->store, $this->store, $this->store, $this->clock, new NullLogger(), false, 7);
    }

    public function testKeywordMetricsAreRequestedPerLocaleAndNormalized(): void
    {
        $this->http->json('POST', 'https://api.dataforseo.com' . DataForSeoKeywordMetrics::PATH, self::envelope(0.075, [[
            'id' => 'task-1',
            'status_code' => 20000,
            'result' => [
                [
                    'keyword' => 'red shoes', 'search_volume' => 12100, 'cpc' => 1.37, 'competition' => 'HIGH', 'competition_index' => 88,
                    'monthly_searches' => [
                        ['year' => 2026, 'month' => 7, 'search_volume' => 9900],
                        ['year' => 2026, 'month' => 8, 'search_volume' => 12100],
                    ],
                ],
                ['keyword' => 'rare phrase', 'search_volume' => null, 'cpc' => null, 'competition' => null, 'competition_index' => null, 'monthly_searches' => null],
            ],
        ]]));
        $provider = new DataForSeoKeywordMetrics($this->client, $this->store);

        $metrics = $provider->fetch(self::WORKSPACE, 5, ['red shoes', 'rare phrase'], new KeywordLocale(2276, 'de'));

        self::assertCount(2, $metrics);
        self::assertSame(12100, $metrics[0]->searchVolume);
        self::assertSame(1.37, $metrics[0]->cpc);
        self::assertSame(88, $metrics[0]->competition);
        self::assertSame('high', $metrics[0]->competitionLevel);
        self::assertSame(['year' => 2026, 'month' => 8, 'volume' => 12100], $metrics[0]->monthly[0], 'Newest month first.');
        self::assertNull($metrics[1]->searchVolume);

        $body = json_decode((string) $this->http->requests[0]['body'], true);
        self::assertSame([['keywords' => ['red shoes', 'rare phrase'], 'location_code' => 2276, 'language_code' => 'de']], $body);
        self::assertSame(DataForSeoKeywordMetrics::OPERATION, $this->store->usage[1]['operation']);
        self::assertSame(5, $this->store->usage[1]['project_id']);
        self::assertTrue($provider->isAvailable(self::WORKSPACE));
        self::assertFalse($provider->isAvailable(99));
    }

    public function testKeywordMetricsAskForTheCountryAloneWhenGoogleAdsHasNoSuchLanguage(): void
    {
        // Finding 41 (0.44.3): Google Ads refuses Bosnian, so the volume is asked for Bosnia and Herzegovina alone.
        $this->http->json('POST', 'https://api.dataforseo.com' . DataForSeoKeywordMetrics::PATH, self::envelope(0.075, [[
            'id' => 'task-1', 'status_code' => 20000, 'result' => [['keyword' => 'sjemenke', 'search_volume' => 90, 'cpc' => null, 'competition' => null, 'competition_index' => null, 'monthly_searches' => null]],
        ]]));
        $provider = new DataForSeoKeywordMetrics($this->client, $this->store);

        self::assertSame(90, $provider->fetch(self::WORKSPACE, 5, ['sjemenke'], new KeywordLocale(2070, 'bs'))[0]->searchVolume);
        self::assertSame([['keywords' => ['sjemenke'], 'location_code' => 2070]], json_decode((string) $this->http->requests[0]['body'], true));
        $this->http->json('POST', 'https://api.dataforseo.com' . DataForSeoKeywordMetrics::PATH, self::envelope(0.075, [[
            'id' => 'task-2', 'status_code' => 20000, 'result' => [['keyword' => 'семена', 'search_volume' => 320, 'cpc' => null, 'competition' => null, 'competition_index' => null, 'monthly_searches' => null]],
        ]]));
        self::assertSame(320, $provider->fetch(self::WORKSPACE, 5, ['семена'], new KeywordLocale(2688, 'sr'))[0]->searchVolume);
        self::assertSame([['keywords' => ['семена'], 'location_code' => 2688, 'language_code' => 'sr']], json_decode((string) $this->http->requests[1]['body'], true), 'Cyrillic keywords are sent as UTF-8 text');
    }

    public function testKeywordMetricsTaskErrorIsRaised(): void
    {
        $this->http->json('POST', 'https://api.dataforseo.com' . DataForSeoKeywordMetrics::PATH, self::envelope(0, [[
            'status_code' => 40501, 'status_message' => 'Invalid Field: keywords.',
        ]]));

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Invalid Field');
        (new DataForSeoKeywordMetrics($this->client, $this->store))->fetch(self::WORKSPACE, null, ['x'], KeywordLocale::default());
    }

    public function testCreatedTaskCannotBeCachedAsCompletedEmptyKeywordMetrics(): void
    {
        $this->http->json('POST', 'https://api.dataforseo.com' . DataForSeoKeywordMetrics::PATH, self::envelope(0.075, [['status_code' => 20100, 'result' => null]]));
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('incomplete keyword metrics');
        (new DataForSeoKeywordMetrics($this->client, $this->store))->fetch(self::WORKSPACE, null, ['shoes'], KeywordLocale::default());
    }

    public function testMissingKeywordResultIsNotCachedAsEmptyMetrics(): void
    {
        $this->http->json('POST', 'https://api.dataforseo.com' . DataForSeoKeywordMetrics::PATH, self::envelope(0.075, [['status_code' => 20000]]));
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('invalid keyword metrics');
        (new DataForSeoKeywordMetrics($this->client, $this->store))->fetch(self::WORKSPACE, null, ['shoes'], KeywordLocale::default());
    }

    public function testUnrequestedKeywordMetricsAreRejected(): void
    {
        $this->http->json('POST', 'https://api.dataforseo.com' . DataForSeoKeywordMetrics::PATH, self::envelope(0.075, [['status_code' => 20000, 'result' => [['keyword' => 'another keyword', 'search_volume' => 1000]]]]));
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('unrequested keyword');
        (new DataForSeoKeywordMetrics($this->client, $this->store))->fetch(self::WORKSPACE, null, ['shoes'], KeywordLocale::default());
    }

    public function testProviderDecodedKeywordIsStoredUnderTheRequestedIdentity(): void
    {
        $this->http->json('POST', 'https://api.dataforseo.com' . DataForSeoKeywordMetrics::PATH, self::envelope(0.075, [['status_code' => 20000, 'result' => [['keyword' => 'c   tutorial', 'search_volume' => 100]]]]));
        $rows = (new DataForSeoKeywordMetrics($this->client, $this->store))->fetch(self::WORKSPACE, null, ['c++ tutorial'], KeywordLocale::default());
        self::assertCount(1, $rows);
        self::assertSame('c++ tutorial', $rows[0]->keyword);
        self::assertSame(100, $rows[0]->searchVolume);
    }

    public function testAmbiguousDecodedKeywordsAreNotAssignedToTheWrongRequest(): void
    {
        $this->http->json('POST', 'https://api.dataforseo.com' . DataForSeoKeywordMetrics::PATH, self::envelope(0.075, [['status_code' => 20000, 'result' => [['keyword' => 'c tutorial', 'search_volume' => 100]]]]));
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('ambiguous keyword');
        (new DataForSeoKeywordMetrics($this->client, $this->store))->fetch(self::WORKSPACE, null, ['c tutorial', 'c++ tutorial'], KeywordLocale::default());
    }

    public function testReturnedKeywordNormalizationAndDuplicates(): void
    {
        $this->http->json('POST', 'https://api.dataforseo.com' . DataForSeoKeywordMetrics::PATH, self::envelope(0.075, [['status_code' => 20000, 'result' => [['keyword' => ' RED  SHOES ', 'search_volume' => 100], ['keyword' => 'red shoes', 'search_volume' => 100]]]]));
        $rows = (new DataForSeoKeywordMetrics($this->client, $this->store))->fetch(self::WORKSPACE, null, ['red shoes'], KeywordLocale::default());
        self::assertCount(1, $rows);
        self::assertSame('red shoes', $rows[0]->keyword);
    }

    public function testNonFiniteKeywordMetricCannotReachStorage(): void
    {
        $this->http->on('POST', 'https://api.dataforseo.com' . DataForSeoKeywordMetrics::PATH, static fn(): \SeoControlCenter\Core\Http\Client\HttpResponse => new \SeoControlCenter\Core\Http\Client\HttpResponse(200, '{"status_code":20000,"cost":0.075,"tasks":[{"status_code":20000,"result":[{"keyword":"shoes","cpc":1e400}]}]}'));
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('invalid numeric');
        (new DataForSeoKeywordMetrics($this->client, $this->store))->fetch(self::WORKSPACE, null, ['shoes'], KeywordLocale::default());
    }

    public function testMalformedNumericKeywordFieldsCannotBeSavedAsUnavailable(): void
    {
        foreach (['search_volume', 'cpc', 'competition_index'] as $field) {
            foreach ([false, '10', []] as $value) {
                $this->http->json('POST', 'https://api.dataforseo.com' . DataForSeoKeywordMetrics::PATH, self::envelope(0.075, [['status_code' => 20000, 'result' => [['keyword' => 'shoes', $field => $value]]]]));
                try {
                    (new DataForSeoKeywordMetrics($this->client, $this->store))->fetch(self::WORKSPACE, null, ['shoes'], KeywordLocale::default());
                    self::fail('Malformed numeric fields must not replace saved metrics with null.');
                } catch (IntegrationException $e) {
                    self::assertFalse($e->retryable);
                }
            }
        }
    }

    public function testKeywordMetricsMustFitDatabaseColumns(): void
    {
        foreach (['search_volume' => 4294967296, 'competition_index' => 256, 'cpc' => 100000000] as $field => $value) {
            $this->http->json('POST', 'https://api.dataforseo.com' . DataForSeoKeywordMetrics::PATH, self::envelope(0.075, [['status_code' => 20000, 'result' => [['keyword' => 'shoes', $field => $value]]]]));
            try {
                (new DataForSeoKeywordMetrics($this->client, $this->store))->fetch(self::WORKSPACE, null, ['shoes'], KeywordLocale::default());
                self::fail('Out-of-range values must fail before database persistence can trigger a paid retry.');
            } catch (IntegrationException $e) {
                self::assertFalse($e->retryable);
            }
        }
    }

    public function testInvalidCompetitionMetadataIsRejectedWithoutPaidRetry(): void
    {
        foreach ([['competition_index' => 101], ['competition_index' => 255], ['competition' => 'unexpected'], ['competition' => '高'], ['competition' => []]] as $fields) {
            $this->http->json('POST', 'https://api.dataforseo.com' . DataForSeoKeywordMetrics::PATH, self::envelope(0.075, [['status_code' => 20000, 'result' => [['keyword' => 'shoes', ...$fields]]]]));
            try {
                (new DataForSeoKeywordMetrics($this->client, $this->store))->fetch(self::WORKSPACE, null, ['shoes'], KeywordLocale::default());
                self::fail('Invalid competition must not be silently changed or persisted.');
            } catch (IntegrationException $e) {
                self::assertFalse($e->retryable);
            }
        }
    }

    public function testCompetitionBoundariesAndLevelsArePreserved(): void
    {
        foreach ([[0, 'LOW'], [100, 'HIGH'], [50, 'MEDIUM'], [null, null]] as [$index, $level]) {
            $this->http->json('POST', 'https://api.dataforseo.com' . DataForSeoKeywordMetrics::PATH, self::envelope(0.075, [['status_code' => 20000, 'result' => [['keyword' => 'shoes', 'competition_index' => $index, 'competition' => $level]]]]));
            $rows = (new DataForSeoKeywordMetrics($this->client, $this->store))->fetch(self::WORKSPACE, null, ['shoes'], KeywordLocale::default());
            self::assertSame($index, $rows[0]->competition);
            self::assertSame($level === null ? null : strtolower($level), $rows[0]->competitionLevel);
        }
    }

    public function testMonthlyHistoryExcludesInvalidAndDuplicateMonths(): void
    {
        $monthly = [['year' => 2026, 'month' => 8, 'search_volume' => 100], ['year' => 2026, 'month' => 8, 'search_volume' => 110], ['year' => 2026, 'month' => 13, 'search_volume' => 500], ['year' => 0, 'month' => 1, 'search_volume' => 500]];
        $this->http->json('POST', 'https://api.dataforseo.com' . DataForSeoKeywordMetrics::PATH, self::envelope(0.075, [['status_code' => 20000, 'result' => [['keyword' => 'shoes', 'monthly_searches' => $monthly]]]]));
        $rows = (new DataForSeoKeywordMetrics($this->client, $this->store))->fetch(self::WORKSPACE, null, ['shoes'], KeywordLocale::default());
        self::assertSame([['year' => 2026, 'month' => 8, 'volume' => 110]], $rows[0]->monthly);
    }

    public function testCreatedSerpTaskCannotBecomeACompletedEmptyRankingSnapshot(): void
    {
        $this->http->json('POST', 'https://api.dataforseo.com' . DataForSeoSerp::PATH, self::envelope(0.002, [['status_code' => 20100, 'result' => [['items' => []]]]]));
        try {
            (new DataForSeoSerp($this->client, $this->store, $this->clock))->organic(self::WORKSPACE, null, 'shoes', KeywordLocale::default(), SerpDevice::Desktop);
            self::fail('An unfinished paid task must not clear saved rankings.');
        } catch (IntegrationException $e) {
            self::assertFalse($e->retryable);
        }
        self::assertCount(1, $this->http->requests);
        self::assertSame('charged', $this->store->usage[1]['status']);
    }

    public function testMissingSerpItemsCannotBecomeAnEmptyRankingSnapshot(): void
    {
        $this->http->json('POST', 'https://api.dataforseo.com' . DataForSeoSerp::PATH, self::envelope(0.002, [['status_code' => 20000, 'result' => [['keyword' => 'shoes']]]]));
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('invalid SERP result');
        (new DataForSeoSerp($this->client, $this->store, $this->clock))->organic(self::WORKSPACE, null, 'shoes', KeywordLocale::default(), SerpDevice::Desktop);
    }

    public function testExplicitlyEmptySerpItemsAreAccepted(): void
    {
        $this->http->json('POST', 'https://api.dataforseo.com' . DataForSeoSerp::PATH, self::envelope(0.002, [['status_code' => 20000, 'result' => [['items' => null]]]]));
        $snapshot = (new DataForSeoSerp($this->client, $this->store, $this->clock))->organic(self::WORKSPACE, null, 'shoes', KeywordLocale::default(), SerpDevice::Desktop);
        self::assertSame([], $snapshot->organic);
    }

    public function testMalformedSerpRowsDoNotClearRankings(): void
    {
        foreach ([['unexpected' => []], ['invalid row'], [[]], [['type' => '']], [['rank_group' => 1, 'url' => 'https://shop.test/']], [['type' => 'organic', 'rank_group' => 1, 'url' => 'https://shop.test/'], 'invalid row'], [['type' => 'organic', 'rank_group' => 0, 'url' => 'https://shop.test/']], [['type' => 'organic', 'rank_group' => 1]]] as $items) {
            $this->http->json('POST', 'https://api.dataforseo.com' . DataForSeoSerp::PATH, self::envelope(0.002, [['status_code' => 20000, 'result' => [['items' => $items]]]]));
            try {
                (new DataForSeoSerp($this->client, $this->store, $this->clock))->organic(self::WORKSPACE, null, 'shoes', KeywordLocale::default(), SerpDevice::Desktop);
                self::fail('Malformed organic data must not become a ranking snapshot.');
            } catch (IntegrationException $e) {
                self::assertFalse($e->retryable);
            }
        }
    }

    public function testSerpIsNormalizedWithFeaturesAndPositions(): void
    {
        $this->http->json('POST', 'https://api.dataforseo.com' . DataForSeoSerp::PATH, self::envelope(0.002, [[
            'id' => 'serp-1',
            'status_code' => 20000,
            'result' => [[
                'keyword' => 'red shoes',
                'se_results_count' => 1250000,
                'items' => [
                    ['type' => 'featured_snippet', 'rank_group' => 1, 'rank_absolute' => 1, 'url' => 'https://snippet.test/'],
                    ['type' => 'organic', 'rank_group' => 2, 'rank_absolute' => 4, 'domain' => 'www.Shop.acme.test', 'url' => 'https://www.shop.acme.test/red', 'title' => 'Red shoes'],
                    ['type' => 'organic', 'rank_group' => 1, 'rank_absolute' => 2, 'domain' => 'rival.test', 'url' => 'https://rival.test/', 'title' => 'Rival', 'description' => 'Best'],
                    ['type' => 'people_also_ask', 'rank_group' => 1, 'rank_absolute' => 3],
                ],
            ]],
        ]]));
        $serp = new DataForSeoSerp($this->client, $this->store, $this->clock);

        $snapshot = $serp->organic(self::WORKSPACE, null, 'red shoes', new KeywordLocale(2840, 'en'), SerpDevice::Mobile, 20);

        self::assertSame(['featured_snippet', 'people_also_ask'], $snapshot->features);
        self::assertSame('rival.test', $snapshot->organic[0]->domain);
        self::assertSame(4, $snapshot->organic[1]->absolutePosition);
        self::assertSame(2, $snapshot->positionOf('acme.test'), 'Subdomains count for the domain.');
        self::assertNull($snapshot->positionOf('other.test'));
        self::assertSame(1250000, $snapshot->totalResults);
        self::assertSame('2026-09-26 12:00:00', $snapshot->checkedAt);

        $body = json_decode((string) $this->http->requests[0]['body'], true);
        self::assertSame('mobile', $body[0]['device']);
        self::assertSame(20, $body[0]['depth']);
        self::assertEqualsWithDelta(0.004, $this->store->usage[1]['estimated'], 0.000001);
        self::assertTrue($body[0]['load_async_ai_overview']);
        self::assertNull($snapshot->aiOverview);
    }

    public function testAiOverviewSourcesAreCollected(): void
    {
        $this->http->json('POST', 'https://api.dataforseo.com' . DataForSeoSerp::PATH, self::envelope(0.002, [[
            'id' => 'serp-2',
            'status_code' => 20000,
            'result' => [[
                'keyword' => 'best spade',
                'items' => [
                    ['type' => 'ai_overview', 'rank_group' => 1, 'rank_absolute' => 1,
                        'references' => [
                            ['type' => 'ai_overview_reference', 'domain' => 'Guide.test', 'url' => 'https://guide.test/spades', 'title' => 'Spade guide'],
                            ['type' => 'ai_overview_reference', 'domain' => 'shop.acme.test', 'url' => 'https://shop.acme.test/spades'],
                        ],
                        'items' => [
                            ['type' => 'ai_overview_element', 'references' => [
                                ['domain' => 'guide.test', 'url' => 'https://guide.test/spades'],
                                ['url' => 'https://www.rival.test/spade', 'title' => 'Rival'],
                                'not a reference',
                            ]],
                        ],
                    ],
                    ['type' => 'organic', 'rank_group' => 1, 'rank_absolute' => 2, 'domain' => 'shop.acme.test', 'url' => 'https://shop.acme.test/spades'],
                ],
            ]],
        ]]));

        $snapshot = (new DataForSeoSerp($this->client, $this->store, $this->clock))->organic(self::WORKSPACE, null, 'best spade', KeywordLocale::default(), SerpDevice::Desktop, 10);

        self::assertSame(['ai_overview'], $snapshot->features);
        self::assertNotNull($snapshot->aiOverview);
        self::assertSame(['guide.test', 'shop.acme.test', 'www.rival.test'], array_map(static fn($r): string => $r->domain, $snapshot->aiOverview->references));
        self::assertSame('Spade guide', $snapshot->aiOverview->references[0]->title);
        self::assertSame(2, $snapshot->aiOverview->positionOf('acme.test'));
        self::assertSame(3, $snapshot->aiOverview->positionOf('rival.test'));
        self::assertNull($snapshot->aiOverview->positionOf('other.test'));
        self::assertSame('https://shop.acme.test/spades', $snapshot->aiOverview->referenceFor('acme.test')?->url);
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return array<string, mixed>
     */
    private static function envelope(float|int $cost, array $tasks): array
    {
        return ['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => $cost, 'tasks' => $tasks];
    }

    public function testOrganicAndAiOverviewAttributionUsesUrlHosts(): void
    {
        $items = [
            ['type' => 'organic', 'rank_group' => 1, 'domain' => 'shop.test', 'url' => 'https://rival.test/'],
            ['type' => 'ai_overview', 'references' => [
                ['domain' => 'shop.test', 'url' => 'https://rival.test/guide'],
                ['domain' => 'shop.test', 'url' => 'javascript:alert(1)'],
                ['domain' => 'rival.test', 'url' => 'https://www.shop.test/page'],
            ]],
        ];
        $this->http->json('POST', 'https://api.dataforseo.com' . DataForSeoSerp::PATH, self::envelope(0.002, [['status_code' => 20000, 'result' => [['items' => $items]]]]));
        $snapshot = (new DataForSeoSerp($this->client, $this->store, $this->clock))->organic(self::WORKSPACE, null, 'shoes', KeywordLocale::default(), SerpDevice::Desktop);
        self::assertNull($snapshot->positionOf('shop.test'));
        self::assertSame(1, $snapshot->positionOf('rival.test'));
        self::assertNotNull($snapshot->aiOverview);
        self::assertSame(2, $snapshot->aiOverview->positionOf('shop.test'));
        self::assertCount(2, $snapshot->aiOverview->references);
    }
}
