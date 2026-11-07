<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\DataForSEO;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Research\AiMentionsProvider;
use SeoControlCenter\Modules\DataForSEO\Adapters\DataForSeoAiMentions;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\DataForSeoCredentials;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDataForSeo;

/**
 * DataForSEO LLM Mentions responses normalized into AI mentions and answers.
 */
final class DataForSeoAiMentionsTest extends TestCase
{
    private const int WORKSPACE = 3;
    private const string API = 'https://api.dataforseo.com';

    private FakeHttpClient $http;

    private InMemoryDataForSeo $store;

    private DataForSeoAiMentions $mentions;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-03 12:00:00', new \DateTimeZone('UTC')));
        $this->store = new InMemoryDataForSeo($clock);
        $this->store->save(self::WORKSPACE, new DataForSeoCredentials('api@acme.test', 'secret'), 10.0, 1);
        $client = new DataForSeoClient($this->http, $this->store, $this->store, $this->store, $clock, new NullLogger(), false, 7);
        $this->mentions = new DataForSeoAiMentions($client, $this->store);
    }

    public function testBrandPayloadUsesAnswerWordMatchingAndSeparatesSourceEvidence(): void
    {
        $this->http->json('POST', self::API . DataForSeoAiMentions::METRICS, ['status_code' => 20000, 'cost' => 0.101, 'tasks' => [['id' => 'brand', 'status_code' => 20000, 'result' => [['aggregated_metrics' => ['total' => ['mentions' => 9, 'ai_search_volume' => 420]]]]]]]);
        self::assertSame(['mentions' => 9, 'ai_search_volume' => 420], $this->mentions->brandMetrics(self::WORKSPACE, 5, 'Acme Ω', 'chat_gpt', new KeywordLocale(2276, 'de')));
        $body = json_decode((string) $this->http->requests[0]['body'], true)[0];
        self::assertSame([['keyword' => 'Acme Ω', 'search_filter' => 'include', 'search_scope' => ['answer'], 'match_type' => 'word_match']], $body['target']);
        self::assertSame(['chat_gpt', 2840, 'en'], [$body['platform'], $body['location_code'], $body['language_code']]);
        $this->http->json('POST', self::API . DataForSeoAiMentions::SEARCH, ['status_code' => 20000, 'cost' => 0.102, 'tasks' => [['id' => 'answers', 'status_code' => 20000, 'result' => [['items' => [['question' => 'Which shop?', 'answer' => 'Acme Ω', 'sources' => []], ['question' => 'Which store?', 'sources' => [['domain' => 'shop.test', 'url' => 'https://shop.test/', 'rank' => 3]]]]]]]]]);
        $answers = $this->mentions->brandAnswers(self::WORKSPACE, 5, 'Acme Ω', 'shop.test', 'google', new KeywordLocale(2276, 'de'), 25);
        self::assertCount(2, $answers);
        self::assertNull($answers[0]->sourceRank);
        self::assertSame(3, $answers[1]->sourceRank);
        $body = json_decode((string) $this->http->requests[1]['body'], true)[0];
        self::assertSame(['google', 2276, 'de'], [$body['platform'], $body['location_code'], $body['language_code']]);
        self::assertSame('Acme Ω', $body['target'][0]['keyword']);
        self::assertEqualsWithDelta(0.203, $this->store->summary(self::WORKSPACE, 'dataforseo')->today, 0.000001);
    }

    public function testCreatedTaskCannotBecomeCompletedMentionMetrics(): void
    {
        foreach (['domain', 'brand'] as $mode) {
            $this->http->json('POST', self::API . DataForSeoAiMentions::METRICS, ['status_code' => 20000, 'cost' => 0.101, 'tasks' => [['status_code' => 20100, 'result' => [['aggregated_metrics' => ['total' => ['mentions' => 0]]]]]]]);
            try {
                if ($mode === 'domain') {
                    $this->mentions->metrics(self::WORKSPACE, 5, ['shop.test'], 'google', KeywordLocale::default());
                } else {
                    $this->mentions->brandMetrics(self::WORKSPACE, 5, 'Acme', 'google', KeywordLocale::default());
                }
                self::fail('An unfinished task must not replace saved mention metrics.');
            } catch (\SeoControlCenter\Domain\Integrations\IntegrationException $e) {
                self::assertFalse($e->retryable);
            }
        }
        self::assertCount(2, $this->http->requests);
    }

    public function testMissingBrandMetricsAreNotRecordedAsZero(): void
    {
        $this->http->json('POST', self::API . DataForSeoAiMentions::METRICS, ['status_code' => 20000, 'cost' => 0.101, 'tasks' => [['id' => 'missing', 'status_code' => 20000, 'result' => [['aggregated_metrics' => []]]]]]);
        $this->expectException(\SeoControlCenter\Domain\Integrations\IntegrationException::class);
        $this->expectExceptionMessage('Missing data is not zero');
        $this->mentions->brandMetrics(self::WORKSPACE, 5, 'Acme', 'google', KeywordLocale::default());
    }

    public function testCitationAttributionUsesTheUrlHost(): void
    {
        $items = [['question' => 'Which shop?', 'sources' => [
            ['domain' => 'shop.test', 'url' => 'https://rival.test/', 'rank' => 1],
            ['domain' => 'shop.test', 'url' => 'javascript:alert(1)', 'rank' => 2],
            ['domain' => 'rival.test', 'url' => 'https://www.shop.test/page', 'rank' => 3],
        ]]];
        $this->http->json('POST', self::API . DataForSeoAiMentions::SEARCH, ['status_code' => 20000, 'cost' => 0.101, 'tasks' => [['status_code' => 20000, 'result' => [['items' => $items]]]]]);
        $answers = $this->mentions->answers(self::WORKSPACE, 5, 'SHOP.TEST', 'google', KeywordLocale::default(), 10);
        self::assertSame(3, $answers[0]->sourceRank);
        self::assertSame('https://www.shop.test/page', $answers[0]->sourceUrl);
        self::assertSame(['rival.test', 'www.shop.test'], $answers[0]->sources);
    }

    public function testMissingAnswerItemsDoNotBecomeEmptyAnswers(): void
    {
        $this->http->json('POST', self::API . DataForSeoAiMentions::SEARCH, ['status_code' => 20000, 'cost' => 0.1, 'tasks' => [['status_code' => 20000, 'result' => [[]]]]]);
        $this->expectException(\SeoControlCenter\Domain\Integrations\IntegrationException::class);
        $this->mentions->answers(self::WORKSPACE, 5, 'shop.test', 'google', KeywordLocale::default(), 10);
    }

    public function testMissingDomainMetricsAreNotRecordedAsZero(): void
    {
        $this->http->json('POST', self::API . DataForSeoAiMentions::METRICS, ['status_code' => 20000, 'cost' => 0.101, 'tasks' => [['status_code' => 20000, 'result' => [['aggregated_metrics' => []]]]]]);
        $this->expectException(\SeoControlCenter\Domain\Integrations\IntegrationException::class);
        $this->expectExceptionMessage('Missing data is not zero');
        $this->mentions->metrics(self::WORKSPACE, 5, ['shop.test'], 'google', KeywordLocale::default());
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed> the live 2026-10-10 response result for a domain with no mentions
     */
    private static function emptyAggregate(array $override = []): array
    {
        return [...['total_count' => 0, 'offset' => 0, 'items_count' => 0, 'aggregated_metrics' => [
            'location' => [], 'language' => [], 'platform' => [], 'sources_domain' => [],
            'search_results_domain' => [], 'brand_entities_title' => [], 'brand_entities_category' => [],
        ], 'items' => []], ...$override];
    }

    public function testAnEmptyAggregateIsZeroMentionsForDomainsAndBrands(): void
    {
        // Live: northwindgoods.test on chat_gpt returned this "Ok." result (cost 0.101) with no `total`;
        // treating it as invalid failed every mention check at its first domain.
        $this->http->json('POST', self::API . DataForSeoAiMentions::METRICS, ['status_code' => 20000, 'cost' => 0.101, 'tasks' => [['status_code' => 20000, 'result' => [self::emptyAggregate()]]]]);

        $rows = $this->mentions->metrics(self::WORKSPACE, 5, ['shop.test'], 'chat_gpt', KeywordLocale::default());
        self::assertCount(1, $rows);
        self::assertSame([0, 0], [$rows[0]->mentions, $rows[0]->aiSearchVolume]);
        self::assertSame(['mentions' => 0, 'ai_search_volume' => 0], $this->mentions->brandMetrics(self::WORKSPACE, 5, 'Acme', 'google', KeywordLocale::default()));
    }

    public function testAPartlyFilledAggregateWithoutATotalIsStillNotZero(): void
    {
        foreach ([
            self::emptyAggregate(['aggregated_metrics' => [...self::emptyAggregate()['aggregated_metrics'], 'platform' => [['key' => 'google', 'mentions' => 4]]]]),
            self::emptyAggregate(['total_count' => 3]),
            self::emptyAggregate(['items' => [['question' => 'x']]]),
        ] as $result) {
            $this->http->json('POST', self::API . DataForSeoAiMentions::METRICS, ['status_code' => 20000, 'cost' => 0.101, 'tasks' => [['status_code' => 20000, 'result' => [$result]]]]);
            try {
                $this->mentions->metrics(self::WORKSPACE, 5, ['shop.test'], 'google', KeywordLocale::default());
                self::fail('Partial data must not be recorded as zero.');
            } catch (\SeoControlCenter\Domain\Integrations\IntegrationException $e) {
                self::assertStringContainsString('Missing data is not zero', $e->getMessage());
            }
        }
    }

    public function testInvalidSearchVolumesCannotBeSavedOrConvertedToZero(): void
    {
        foreach (['domain', 'brand', 'answers'] as $mode) {
            foreach ([-1, 1.5, false, []] as $volume) {
                $result = $mode === 'answers'
                    ? ['items' => [['question' => 'Valid question', 'ai_search_volume' => $volume]]]
                    : ['aggregated_metrics' => ['total' => ['mentions' => 1, 'ai_search_volume' => $volume]]];
                $path = $mode === 'answers' ? DataForSeoAiMentions::SEARCH : DataForSeoAiMentions::METRICS;
                $this->http->json('POST', self::API . $path, ['status_code' => 20000, 'cost' => 0.101, 'tasks' => [['status_code' => 20000, 'result' => [$result]]]]);
                try {
                    match ($mode) {
                        'domain' => $this->mentions->metrics(self::WORKSPACE, 5, ['shop.test'], 'google', KeywordLocale::default()),
                        'brand' => $this->mentions->brandMetrics(self::WORKSPACE, 5, 'Acme', 'google', KeywordLocale::default()),
                        default => $this->mentions->answers(self::WORKSPACE, 5, 'shop.test', 'google', KeywordLocale::default(), 25),
                    };
                    self::fail('Invalid search volumes must not replace saved research or become zero.');
                } catch (\SeoControlCenter\Domain\Integrations\IntegrationException $e) {
                    self::assertFalse($e->retryable);
                }
            }
        }
    }

    public function testDomainMentionCountsMustFitHistoryStorage(): void
    {
        foreach ([0, 4294967295, 4294967296] as $count) {
            $this->http->json('POST', self::API . DataForSeoAiMentions::METRICS, ['status_code' => 20000, 'cost' => 0.101, 'tasks' => [['status_code' => 20000, 'result' => [['aggregated_metrics' => ['total' => ['mentions' => $count]]]]]]]);
            try {
                $rows = $this->mentions->metrics(self::WORKSPACE, 5, ['shop.test'], 'google', KeywordLocale::default());
                self::assertSame($count, $rows[0]->mentions);
                self::assertLessThanOrEqual(4294967295, $count, 'Oversized counts must fail before history persistence.');
            } catch (\SeoControlCenter\Domain\Integrations\IntegrationException $e) {
                self::assertSame(4294967296, $count);
                self::assertFalse($e->retryable);
            }
        }
    }

    public function testMetricsPerDomain(): void
    {
        $this->http->on('POST', self::API . DataForSeoAiMentions::METRICS, static function (string $url, array $headers, ?string $body): HttpResponse {
            $domain = json_decode((string) $body, true)[0]['target'][0]['domain'] ?? '';
            $task = match ($domain) {
                'shop.acme.test' => ['id' => 'a', 'status_code' => 20000, 'result' => [['aggregated_metrics' => ['total' => ['mentions' => 42, 'ai_search_volume' => 9100]]]]],
                'quiet.test' => ['id' => 'b', 'status_code' => 20000, 'result' => [['aggregated_metrics' => ['total' => ['mentions' => 0]]]]],
                default => ['id' => 'c', 'status_code' => 40501, 'status_message' => 'Invalid Field: domain.'],
            };

            return new HttpResponse(200, (string) json_encode(['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.101, 'tasks' => [$task]]));
        });

        $result = $this->mentions->metrics(self::WORKSPACE, 5, ['shop.acme.test', 'bad..test', 'quiet.test'], AiMentionsProvider::CHATGPT, new KeywordLocale(2276, 'de'));

        self::assertSame([['shop.acme.test', 'chat_gpt', 42, 9100], ['quiet.test', 'chat_gpt', 0, null]], array_map(static fn($m): array => array_values((array) $m), $result));
        $first = json_decode((string) $this->http->requests[0]['body'], true)[0];
        self::assertSame([['domain' => 'shop.acme.test', 'search_filter' => 'include', 'search_scope' => ['any'], 'include_subdomains' => true]], $first['target']);
        self::assertSame(['chat_gpt', 2840, 'en'], [$first['platform'], $first['location_code'], $first['language_code']], 'ChatGPT data exists for the US in English only');
        self::assertCount(3, $this->http->requests, 'one request per domain');
        self::assertEqualsWithDelta(0.101, $this->store->usage[1]['estimated'], 0.000001);

        $this->mentions->metrics(self::WORKSPACE, 5, ['shop.acme.test'], AiMentionsProvider::GOOGLE, new KeywordLocale(2276, 'de'));
        $google = json_decode((string) $this->http->requests[3]['body'], true)[0];
        self::assertSame(['google', 2276, 'de'], [$google['platform'], $google['location_code'], $google['language_code']]);
    }

    public function testMalformedQuestionsCannotBecomeEmptyOrPartialAnswerResearch(): void
    {
        foreach ([[], ['question' => ''], ['question' => '   '], ['question' => []]] as $row) {
            $items = [['question' => 'Valid question', 'sources' => []], $row];
            $this->http->json('POST', self::API . DataForSeoAiMentions::SEARCH, ['status_code' => 20000, 'cost' => 0.12, 'tasks' => [['status_code' => 20000, 'result' => [['items' => $items]]]]]);
            try {
                $this->mentions->answers(self::WORKSPACE, null, 'shop.test', AiMentionsProvider::CHATGPT, KeywordLocale::default(), 25);
                self::fail('Malformed questions must not replace saved answer research.');
            } catch (\SeoControlCenter\Domain\Integrations\IntegrationException $e) {
                self::assertFalse($e->retryable);
            }
        }
        $this->http->json('POST', self::API . DataForSeoAiMentions::SEARCH, ['status_code' => 20000, 'cost' => 0.1, 'tasks' => [['status_code' => 20000, 'result' => [['items' => []]]]]]);
        self::assertSame([], $this->mentions->answers(self::WORKSPACE, null, 'shop.test', AiMentionsProvider::CHATGPT, KeywordLocale::default(), 25));
    }

    public function testAnswers(): void
    {
        $this->http->json('POST', self::API . DataForSeoAiMentions::SEARCH, ['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.12, 'tasks' => [[
            'id' => 't', 'status_code' => 20000, 'result' => [['items' => [
                [
                    'platform' => 'chat_gpt',
                    'question' => 'best sunflower seeds',
                    'answer' => "## Top picks\n\nTry **[Acme](https://shop.acme.test/auto)** or *Rival* — " . str_repeat('more text ', 50),
                    'ai_search_volume' => 2400,
                    'last_response_at' => '2026-09-30 10:00:00 +00:00',
                    'sources' => [
                        ['domain' => 'rival.test', 'url' => 'https://rival.test/', 'rank' => 1],
                        ['domain' => 'www.shop.acme.test', 'url' => 'https://www.shop.acme.test/auto', 'rank' => 2],
                        ['url' => 'https://guide.test/x'],
                    ],
                ],
                ['platform' => 'chat_gpt', 'question' => 'acme seeds review', 'answer' => 'Acme is a seed shop.', 'sources' => []],
            ]]],
        ]]]);

        $answers = $this->mentions->answers(self::WORKSPACE, null, 'shop.acme.test', AiMentionsProvider::CHATGPT, KeywordLocale::default(), 25);

        self::assertCount(2, $answers);
        self::assertSame('best sunflower seeds', $answers[0]->question);
        self::assertSame(2, $answers[0]->sourceRank);
        self::assertSame('https://www.shop.acme.test/auto', $answers[0]->sourceUrl);
        self::assertSame(['rival.test', 'www.shop.acme.test', 'guide.test'], $answers[0]->sources);
        self::assertStringStartsWith('Top picks Try Acme or Rival — more text', (string) $answers[0]->excerpt);
        self::assertSame(300, mb_strlen((string) $answers[0]->excerpt));
        self::assertSame('2026-09-30', $answers[0]->lastSeen);
        self::assertNull($answers[1]->sourceRank, 'mentioned in the answer, not cited');
        $body = json_decode((string) $this->http->requests[0]['body'], true)[0];
        self::assertSame([25, ['ai_search_volume,desc']], [$body['limit'], $body['order_by']]);
        self::assertEqualsWithDelta(0.125, $this->store->usage[1]['estimated'], 0.000001);
    }
}
