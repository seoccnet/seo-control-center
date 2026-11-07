<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\DataForSEO;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\DataForSEO\Adapters\DataForSeoAiAnswers;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\DataForSeoCredentials;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDataForSeo;

/**
 * DataForSEO LLM Responses (Gemini, Perplexity) as documented: model from the
 * free models list with web search, answer text from message sections and
 * sources from annotations (direct_url preferred).
 */
final class DataForSeoAiAnswersTest extends TestCase
{
    private const int WORKSPACE = 3;
    private const string API = 'https://api.dataforseo.com';

    private FakeHttpClient $http;
    private InMemoryDataForSeo $store;
    private DataForSeoAiAnswers $answers;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-09 12:00:00', new \DateTimeZone('UTC')));
        $this->store = new InMemoryDataForSeo($clock);
        $this->store->save(self::WORKSPACE, new DataForSeoCredentials('api@acme.test', 'secret'), 10.0, 1);
        $client = new DataForSeoClient($this->http, $this->store, $this->store, $this->store, $clock, new NullLogger(), false, 7);
        $this->answers = new DataForSeoAiAnswers($client, $this->store);
    }

    /** @param list<array{model_name: string, web_search_supported: bool}> $models */
    private function models(string $platform, array $models): void
    {
        $this->http->json('GET', self::API . DataForSeoClient::MODEL_LISTS[$platform], ['status_code' => 20000, 'cost' => 0, 'tasks' => [['status_code' => 20000, 'result' => array_map(static fn(array $m): array => [...$m, 'reasoning' => false, 'task_post_supported' => false], $models)]]]);
    }

    public function testGeminiUsesASearchCapableModelAndReadsTextAndSources(): void
    {
        $this->models('gemini', [['model_name' => 'gemini-1.5-flash-8b', 'web_search_supported' => false], ['model_name' => 'gemini-2.5-flash', 'web_search_supported' => true]]);
        $this->http->json('POST', self::API . DataForSeoAiAnswers::ENDPOINTS['gemini'], ['status_code' => 20000, 'cost' => 0.0216, 'tasks' => [['id' => 't1', 'status_code' => 20000, 'cost' => 0.0216, 'result' => [[
            'model_name' => 'gemini-2.5-flash-001', 'web_search' => true, 'money_spent' => 0.021, 'datetime' => '2026-10-09 12:00:00 +00:00',
            'items' => [
                ['type' => 'reasoning', 'sections' => [['type' => 'summary_text', 'text' => 'internal reasoning']]],
                ['type' => 'message', 'sections' => [['type' => 'text', 'text' => 'Try Northwind Goods or Contoso.', 'annotations' => [
                    ['title' => 'northwindgoods.test', 'url' => 'https://vertexaisearch.cloud.google.com/redirect/abc', 'direct_url' => 'https://www.northwindgoods.test/seeds', 'start_index' => 4, 'end_index' => 17, 'text' => 'Northwind Goods'],
                    ['title' => 'Bad', 'url' => 'javascript:alert(1)'],
                ]]]],
            ],
        ]]]]]);
        $answer = $this->answers->answer(self::WORKSPACE, 5, 'gemini', '  Where to buy seeds?  ');
        $request = json_decode((string) $this->http->requests[1]['body'], true)[0];
        self::assertSame(['user_prompt' => 'Where to buy seeds?', 'model_name' => 'gemini-2.5-flash', 'max_output_tokens' => 1024, 'web_search' => true], $request);
        self::assertSame(['gemini-2.5-flash-001', 'Try Northwind Goods or Contoso.', true], [$answer->model, $answer->text, $answer->webSearch]);
        self::assertSame([['url' => 'https://www.northwindgoods.test/seeds', 'title' => 'northwindgoods.test']], $answer->citations, 'direct_url preferred; unsafe URLs and reasoning dropped');
        self::assertSame(['northwindgoods.test'], $answer->citedHosts());
        self::assertEqualsWithDelta(0.0216, $this->store->summary(self::WORKSPACE, 'dataforseo')->today, 0.000001, 'Settled to the reported cost.');
    }

    public function testPerplexityHasNoWebSearchFieldAndPrefersSonar(): void
    {
        $this->models('perplexity', [['model_name' => 'sonar-pro', 'web_search_supported' => true], ['model_name' => 'sonar', 'web_search_supported' => true]]);
        $this->http->json('POST', self::API . DataForSeoAiAnswers::ENDPOINTS['perplexity'], ['status_code' => 20000, 'cost' => 0.006, 'tasks' => [['status_code' => 20000, 'cost' => 0.006, 'result' => [[
            'model_name' => 'sonar', 'web_search' => true, 'items' => [['type' => 'message', 'sections' => [['type' => 'text', 'text' => 'Several shops.', 'annotations' => [['title' => 'Contoso', 'url' => 'https://www.seedhouse.com/']]]]]],
        ]]]]]);
        $answer = $this->answers->answer(self::WORKSPACE, 5, 'perplexity', 'Best seed bank?');
        $request = json_decode((string) $this->http->requests[1]['body'], true)[0];
        self::assertSame(['user_prompt' => 'Best seed bank?', 'model_name' => 'sonar', 'max_output_tokens' => 1024], $request);
        self::assertSame(['seedhouse.com'], $answer->citedHosts());
    }

    public function testNothingIsPaidWithoutASearchModelOrForInvalidPrompts(): void
    {
        $this->models('gemini', [['model_name' => 'gemini-1.5-flash-8b', 'web_search_supported' => false]]);
        try {
            $this->answers->answer(self::WORKSPACE, 5, 'gemini', 'Where?');
            self::fail('A model without web search must not be used.');
        } catch (IntegrationException $e) {
            self::assertStringContainsString('no Gemini model with web search', $e->getMessage());
        }
        try {
            $this->answers->answer(self::WORKSPACE, 5, 'gemini', str_repeat('x', 501));
            self::fail('Long prompts must be rejected.');
        } catch (IntegrationException $e) {
            self::assertStringContainsString('500 characters', $e->getMessage());
        }
        self::assertSame(['GET'], array_column($this->http->requests, 'method'));
        self::assertSame(0.0, $this->store->summary(self::WORKSPACE, 'dataforseo')->today);
    }

    public function testEmptyOrFailedTasksKeepPreviousAnswers(): void
    {
        $this->models('perplexity', [['model_name' => 'sonar', 'web_search_supported' => true]]);
        $this->http->json('POST', self::API . DataForSeoAiAnswers::ENDPOINTS['perplexity'], ['status_code' => 20000, 'cost' => 0.0006, 'tasks' => [['status_code' => 20000, 'cost' => 0.0006, 'result' => [['model_name' => 'sonar', 'items' => [['type' => 'message', 'sections' => [['type' => 'text', 'text' => '  ']]]]]]]]]);
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('empty answer');
        $this->answers->answer(self::WORKSPACE, 5, 'perplexity', 'Best seed bank?');
    }
}
