<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\DataForSEO;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Modules\DataForSEO\Adapters\DataForSeoLabs;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\DataForSeoCredentials;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDataForSeo;

/**
 * DataForSEO Labs responses normalized into research keywords and domains.
 */
final class DataForSeoLabsTest extends TestCase
{
    private const int WORKSPACE = 3;
    private const string API = 'https://api.dataforseo.com';

    private FakeHttpClient $http;

    private InMemoryDataForSeo $store;

    private DataForSeoLabs $labs;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-03 12:00:00', new \DateTimeZone('UTC')));
        $this->store = new InMemoryDataForSeo($clock);
        $this->store->save(self::WORKSPACE, new DataForSeoCredentials('api@acme.test', 'secret'), 10.0, 1);
        $client = new DataForSeoClient($this->http, $this->store, $this->store, $this->store, $clock, new NullLogger(), false, 7);
        $this->labs = new DataForSeoLabs($client, $this->store);
    }

    public function testMarketsWithoutLabsDataAreRefusedBeforeAnyPaidRequest(): void
    {
        // 0.44.3: Labs covers some country and language pairs only; nothing is sent for the others.
        foreach ([new \SeoControlCenter\Domain\Keywords\KeywordLocale(2191, 'en'), new \SeoControlCenter\Domain\Keywords\KeywordLocale(2004, 'en')] as $locale) {
            try {
                $this->labs->rankedKeywords(self::WORKSPACE, 1, 'shop.acme.test', $locale, 10);
                self::fail('An uncovered market must be refused.');
            } catch (\SeoControlCenter\Domain\Integrations\IntegrationException $e) {
                self::assertFalse($e->retryable);
            }
        }
        self::assertSame([], $this->http->requests);
        self::assertSame([], $this->store->usage, 'nothing was reserved or charged');

        $this->respond(DataForSeoLabs::RANKED, []);
        $this->labs->rankedKeywords(self::WORKSPACE, 1, 'shop.acme.test', new \SeoControlCenter\Domain\Keywords\KeywordLocale(2578, 'no'), 10);
        $body = json_decode((string) $this->http->requests[0]['body'], true);
        self::assertSame([2578, 'nb'], [$body[0]['location_code'] ?? null, $body[0]['language_code'] ?? null], 'Norwegian is sent as Labs writes it');
    }

    public function testRankedKeywords(): void
    {
        $this->respond(DataForSeoLabs::RANKED, [[
            'keyword_data' => [
                'keyword' => 'red shoes',
                'keyword_info' => ['search_volume' => 9900, 'cpc' => 1.25, 'competition' => 0.4],
                'keyword_properties' => ['keyword_difficulty' => 44.6],
                'search_intent_info' => ['main_intent' => 'commercial'],
                'serp_info' => ['serp_item_types' => ['ai_overview', 'organic', 'people_also_ask', 'organic', 'Bad Type!']],
                'avg_backlinks_info' => ['referring_domains' => 312.6, 'rank' => 210],
            ],
            'ranked_serp_element' => ['serp_item' => ['type' => 'organic', 'rank_group' => 3, 'rank_absolute' => 5, 'url' => 'https://shop.acme.test/red', 'etv' => 812.5]],
        ], [
            'keyword_data' => ['keyword' => 'red shoes'],
        ], [
            'keyword_data' => ['keyword' => 'blue shoes', 'keyword_info' => ['search_volume' => null]],
            'ranked_serp_element' => ['serp_item' => ['rank_group' => 9]],
        ]]);

        $keywords = $this->labs->rankedKeywords(self::WORKSPACE, 7, 'shop.acme.test', new KeywordLocale(2276, 'de'), 5000);

        self::assertCount(2, $keywords);
        self::assertSame(['red shoes', 9900, 1.25, 45, 'commercial', 3, 'https://shop.acme.test/red', 812.5, ['ai_overview', 'organic', 'people_also_ask'], 313], array_values((array) $keywords[0]));
        self::assertNull($keywords[1]->searchVolume);
        self::assertNull($keywords[1]->serpTypes, 'No SERP info is unknown, not "no features".');
        self::assertNull($keywords[1]->avgReferringDomains);
        self::assertSame(9, $keywords[1]->position);
        $body = json_decode((string) $this->http->requests[0]['body'], true);
        self::assertSame('shop.acme.test', $body[0]['target']);
        self::assertTrue($body[0]['include_serp_info']);
        self::assertSame(1000, $body[0]['limit'], 'capped at the provider maximum');
        self::assertSame(2276, $body[0]['location_code']);
        self::assertSame('de', $body[0]['language_code']);
        self::assertEqualsWithDelta(0.132, $this->store->usage[1]['estimated'], 0.000001);
    }

    public function testKeywordSuggestionsExpandOneSeed(): void
    {
        $this->respond(DataForSeoLabs::SUGGESTIONS, [[
            'keyword' => 'running shoes for women',
            'keyword_info' => ['search_volume' => 2400, 'cpc' => 0.8],
            'keyword_properties' => ['keyword_difficulty' => 22],
            'search_intent_info' => ['main_intent' => 'commercial'],
            'serp_info' => ['serp_item_types' => ['organic', 'shopping']],
            'avg_backlinks_info' => ['referring_domains' => 'odd'],
        ]]);

        $keywords = $this->labs->keywordSuggestions(self::WORKSPACE, 7, '  Running Shoes ', KeywordLocale::default(), 100);

        self::assertCount(1, $keywords);
        self::assertSame('running shoes for women', $keywords[0]->keyword);
        self::assertSame(22, $keywords[0]->difficulty);
        self::assertSame(['organic', 'shopping'], $keywords[0]->serpTypes);
        self::assertNull($keywords[0]->avgReferringDomains, 'An odd optional value is dropped, the paid lookup still succeeds.');
        $body = json_decode((string) $this->http->requests[0]['body'], true);
        self::assertSame('running shoes', $body[0]['keyword']);
        self::assertTrue($body[0]['include_seed_keyword']);
        self::assertTrue($body[0]['include_serp_info']);
        self::assertSame(100, $body[0]['limit']);
        self::assertSame([], $this->labs->keywordSuggestions(self::WORKSPACE, 7, '  ', KeywordLocale::default(), 100), 'An empty seed sends nothing.');
        self::assertCount(1, $this->http->requests);
    }

    public function testMixedMalformedRowsDoNotBecomePartialKeywordResearch(): void
    {
        $this->respond(DataForSeoLabs::RANKED, [['keyword_data' => ['keyword' => 'red shoes']], 'garbage']);
        try {
            $this->labs->rankedKeywords(self::WORKSPACE, 7, 'shop.acme.test', KeywordLocale::default(), 10);
            self::fail('Malformed results must not replace research with a partial list.');
        } catch (IntegrationException $e) {
            self::assertFalse($e->retryable, 'The paid request must not be automatically repeated.');
        }
        self::assertCount(1, $this->http->requests);
        self::assertSame('charged', $this->store->usage[1]['status']);
    }

    public function testMissingKeywordIdentityCannotClearOrPartiallyReplaceResearch(): void
    {
        foreach (['ranked', 'gap', 'ideas'] as $mode) {
            foreach ([[], ['keyword' => null], ['keyword' => '   '], ['keyword' => []]] as $invalid) {
                $valid = ['keyword' => 'shoes', 'keyword_info' => ['search_volume' => 100]];
                $path = match ($mode) {
                    'ranked' => DataForSeoLabs::RANKED,
                    'gap' => DataForSeoLabs::INTERSECTION,
                    default => DataForSeoLabs::IDEAS,
                };
                $this->respond($path, $mode === 'ideas' ? [$valid, $invalid] : [['keyword_data' => $valid], ['keyword_data' => $invalid]]);
                try {
                    match ($mode) {
                        'ranked' => $this->labs->rankedKeywords(self::WORKSPACE, null, 'shop.test', KeywordLocale::default(), 10),
                        'gap' => $this->labs->keywordGap(self::WORKSPACE, null, 'shop.test', 'rival.test', KeywordLocale::default(), 10),
                        default => $this->labs->keywordIdeas(self::WORKSPACE, null, ['shoes'], KeywordLocale::default(), 10),
                    };
                    self::fail('A missing keyword identity must not silently discard part of a paid result.');
                } catch (\SeoControlCenter\Domain\Integrations\IntegrationException $e) {
                    self::assertFalse($e->retryable);
                }
            }
        }
    }

    public function testExplicitlyEmptyKeywordResearchRemainsValid(): void
    {
        $this->respond(DataForSeoLabs::RANKED, []);
        self::assertSame([], $this->labs->rankedKeywords(self::WORKSPACE, null, 'shop.test', KeywordLocale::default(), 10));
        $this->respond(DataForSeoLabs::INTERSECTION, []);
        self::assertSame([], $this->labs->keywordGap(self::WORKSPACE, null, 'shop.test', 'rival.test', KeywordLocale::default(), 10));
        $this->respond(DataForSeoLabs::IDEAS, []);
        self::assertSame([], $this->labs->keywordIdeas(self::WORKSPACE, null, ['shoes'], KeywordLocale::default(), 10));
    }

    public function testGapAndIdeas(): void
    {
        $this->respond(DataForSeoLabs::INTERSECTION, [[
            'keyword_data' => ['keyword' => 'green shoes', 'keyword_info' => ['search_volume' => 2400]],
            'first_domain_serp_element' => ['rank_group' => 4, 'url' => 'https://rival.test/green', 'etv' => 90],
            'second_domain_serp_element' => null,
        ]]);
        $gap = $this->labs->keywordGap(self::WORKSPACE, null, 'shop.acme.test', 'rival.test', KeywordLocale::default(), 100);
        self::assertSame(4, $gap[0]->position);
        self::assertSame(90.0, $gap[0]->traffic);
        $body = json_decode((string) $this->http->requests[0]['body'], true);
        self::assertSame(['rival.test', 'shop.acme.test', false], [$body[0]['target1'], $body[0]['target2'], $body[0]['intersections']]);

        $this->respond(DataForSeoLabs::IDEAS, [[
            'keyword' => 'running shoes',
            'keyword_info' => ['search_volume' => 50000, 'cpc' => 2],
            'keyword_properties' => ['keyword_difficulty' => 70],
            'search_intent_info' => ['main_intent' => 'commercial'],
        ]]);
        $ideas = $this->labs->keywordIdeas(self::WORKSPACE, null, [' Shoes ', 'shoes', ''], KeywordLocale::default(), 300);
        self::assertSame('running shoes', $ideas[0]->keyword);
        self::assertSame(2.0, $ideas[0]->cpc);
        self::assertSame(70, $ideas[0]->difficulty);
        self::assertNull($ideas[0]->position);
        self::assertSame(['shoes'], json_decode((string) $this->http->requests[1]['body'], true)[0]['keywords']);
        self::assertSame([], $this->labs->keywordIdeas(self::WORKSPACE, null, [' '], KeywordLocale::default(), 300), 'no request without seeds');
        self::assertCount(2, $this->http->requests);
    }

    public function testCompetitorsAndOverview(): void
    {
        $this->respond(DataForSeoLabs::COMPETITORS, [
            ['domain' => 'shop.acme.test', 'intersections' => 900],
            ['domain' => 'Rival.test', 'avg_position' => 12.4, 'intersections' => 120, 'full_domain_metrics' => ['organic' => ['count' => 5000, 'etv' => 9000.5, 'pos_1' => 10, 'pos_2_3' => 20, 'pos_4_10' => 70]]],
        ]);
        $competitors = $this->labs->competitors(self::WORKSPACE, null, 'shop.acme.test', KeywordLocale::default(), 20);
        self::assertCount(1, $competitors, 'the domain itself is left out');
        self::assertSame(['rival.test', 5000, 30, 100, 9000.5, 120, 12.4], array_values((array) $competitors[0]));

        $this->http->on('POST', self::API . DataForSeoLabs::OVERVIEW, static function (string $url, array $headers, ?string $body): HttpResponse {
            $target = json_decode((string) $body, true)[0]['target'] ?? '';
            $task = match ($target) {
                'shop.acme.test' => ['id' => 't1', 'status_code' => 20000, 'result' => [['items' => [['metrics' => ['organic' => ['count' => 300, 'etv' => 1200.0, 'pos_1' => 2, 'pos_2_3' => 3, 'pos_4_10' => 15]]]]]]],
                'big.test' => ['id' => 't3', 'status_code' => 20000, 'result' => [['items' => [['metrics' => ['organic' => ['count' => 9000, 'etv' => 50000]]]]]]],
                default => ['id' => 't2', 'status_code' => 40501, 'status_message' => 'Invalid field.'],
            };

            return new HttpResponse(200, (string) json_encode(['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.012, 'tasks' => [$task]]));
        });
        $overview = $this->labs->overview(self::WORKSPACE, null, ['shop.acme.test', 'rival.test', 'big.test'], KeywordLocale::default());
        self::assertSame(['shop.acme.test', 'big.test'], array_map(static fn($d): string => $d->domain, $overview), 'a rejected domain is left out');
        self::assertSame(['shop.acme.test', 300, 5, 20, 1200.0], array_slice(array_values((array) $overview[0]), 0, 5));
        self::assertNull($overview[1]->top3);
        self::assertCount(4, $this->http->requests, 'one request per domain (live endpoints take one task)');
        foreach (array_slice($this->http->requests, 1) as $request) {
            self::assertCount(1, json_decode((string) $request['body'], true));
        }
    }

    public function testTaskErrorsAreRaised(): void
    {
        $this->http->json('POST', self::API . DataForSeoLabs::RANKED, ['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0, 'tasks' => [
            ['id' => 't', 'status_code' => 40501, 'status_message' => 'Invalid Field: target.'],
        ]]);

        $this->expectException(IntegrationException::class);
        $this->labs->rankedKeywords(self::WORKSPACE, null, 'x', KeywordLocale::default(), 10);
    }

    public function testInvalidNumericMetricsCannotBecomeStoredResearch(): void
    {
        foreach (['search_volume' => ['1e400', '1e30', '-1', 'false', '[]', '"12"'], 'cpc' => ['1e400', '-1', 'false', '[]', '"1.2"']] as $field => $values) {
            foreach ($values as $value) {
                $body = '{"status_code":20000,"cost":0.012,"tasks":[{"status_code":20000,"result":[{"items":[{"keyword_data":{"keyword":"shoes","keyword_info":{"' . $field . '":' . $value . '}}}]}]}]}';
                $this->http->on('POST', self::API . DataForSeoLabs::RANKED, static fn(): HttpResponse => new HttpResponse(200, $body));
                try {
                    $this->labs->rankedKeywords(self::WORKSPACE, null, 'shop.test', KeywordLocale::default(), 10);
                    self::fail('Invalid numeric metrics must not be saved or silently converted to zero.');
                } catch (IntegrationException $e) {
                    self::assertFalse($e->retryable);
                }
            }
        }
    }

    public function testCompetitorRequestNeverExceedsProviderLimit(): void
    {
        $this->respond(DataForSeoLabs::COMPETITORS, []);
        $this->labs->competitors(self::WORKSPACE, null, 'shop.acme.test', KeywordLocale::default(), 5000);
        $body = json_decode((string) $this->http->requests[0]['body'], true);
        self::assertSame(1000, $body[0]['limit']);
        self::assertEqualsWithDelta($this->labs->estimatedCost(1000), $this->store->usage[1]['estimated'], 0.000001);
    }

    public function testTopPositionCountOverflowDoesNotTriggerPaidRetries(): void
    {
        foreach ([['pos_1' => PHP_INT_MAX, 'pos_2_3' => 1, 'pos_4_10' => 0], ['pos_1' => PHP_INT_MAX - 1, 'pos_2_3' => 1, 'pos_4_10' => 1]] as $organic) {
            $this->respond(DataForSeoLabs::OVERVIEW, [['metrics' => ['organic' => $organic]]]);
            try {
                $this->labs->overview(self::WORKSPACE, null, ['shop.test'], KeywordLocale::default());
                self::fail('Overflow must reject the result without a retryable runtime error.');
            } catch (IntegrationException $e) {
                self::assertFalse($e->retryable);
            }
        }
        self::assertCount(2, $this->http->requests);
    }

    public function testMissingDomainFiguresAreUnavailableAndExplicitZerosRemainZero(): void
    {
        $this->respond(DataForSeoLabs::OVERVIEW, [['metrics' => ['organic' => ['pos_1' => 10]]]]);
        $missing = $this->labs->overview(self::WORKSPACE, null, ['shop.test'], KeywordLocale::default())[0];
        self::assertNull($missing->keywords);
        self::assertNull($missing->traffic);
        self::assertNull($missing->top3);
        self::assertNull($missing->top10);
        $this->respond(DataForSeoLabs::OVERVIEW, [['metrics' => ['organic' => ['count' => 0, 'etv' => 0, 'pos_1' => 0, 'pos_2_3' => 0, 'pos_4_10' => 0]]]]);
        $zero = $this->labs->overview(self::WORKSPACE, null, ['shop.test'], KeywordLocale::default())[0];
        self::assertSame(0, $zero->keywords);
        self::assertSame(0.0, $zero->traffic);
        self::assertSame(0, $zero->top3);
        self::assertSame(0, $zero->top10);
    }

    public function testMalformedResearchListsAreNotAcceptedAsEmptyResults(): void
    {
        foreach ([[], ['items' => 'invalid'], ['items' => ['bad row']], ['items' => ['unexpected' => []]]] as $result) {
            $this->http->json('POST', self::API . DataForSeoLabs::RANKED, ['status_code' => 20000, 'cost' => 0.012, 'tasks' => [['status_code' => 20000, 'result' => [$result]]]]);
            try {
                $this->labs->rankedKeywords(self::WORKSPACE, null, 'shop.test', KeywordLocale::default(), 10);
                self::fail('Malformed research data must not replace saved results.');
            } catch (IntegrationException $e) {
                self::assertFalse($e->retryable, 'A paid malformed response is not automatically bought again.');
            }
        }
        $this->respond(DataForSeoLabs::RANKED, []);
        self::assertSame([], $this->labs->rankedKeywords(self::WORKSPACE, null, 'shop.test', KeywordLocale::default(), 10));
    }

    public function testBillingFailureStopsDomainBatch(): void
    {
        $this->http->json('POST', self::API . DataForSeoLabs::OVERVIEW, ['status_code' => 20000, 'cost' => 0, 'tasks' => [['status_code' => 40200, 'status_message' => 'Insufficient balance.']]]);
        try {
            $this->labs->overview(self::WORKSPACE, null, ['one.test', 'two.test'], KeywordLocale::default());
            self::fail('Expected the account failure to stop the batch.');
        } catch (IntegrationException $e) {
            self::assertSame(40200, $e->providerStatusCode);
            self::assertCount(1, $this->http->requests);
        }
    }

    public function testTemporaryFailureAfterPaidProgressDoesNotReplayTheBatch(): void
    {
        $calls = 0;
        $this->http->on('POST', self::API . DataForSeoLabs::OVERVIEW, static function () use (&$calls): HttpResponse {
            ++$calls;
            return $calls === 1
                ? new HttpResponse(200, '{"status_code":20000,"cost":0.012,"tasks":[{"status_code":20000,"result":[{"items":[{"metrics":{"organic":{"count":20}}}]}]}]}')
                : new HttpResponse(500, '{"status_code":50000,"cost":0}');
        });
        try {
            $this->labs->overview(self::WORKSPACE, null, ['one.test', 'two.test'], KeywordLocale::default());
            self::fail('Paid progress must not be replayed automatically.');
        } catch (IntegrationException $e) {
            self::assertFalse($e->retryable);
            self::assertStringContainsString('partially completed', $e->getMessage());
            self::assertSame(0.012, $this->store->usage[1]['cost']);
            self::assertCount(2, $this->http->requests);
        }
    }

    /**
     * @param list<mixed> $items
     */
    private function respond(string $path, array $items): void
    {
        $this->http->json('POST', self::API . $path, ['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.02, 'tasks' => [
            ['id' => 'task-1', 'status_code' => 20000, 'result' => [['total_count' => count($items), 'items' => $items]]],
        ]]);
    }
}
