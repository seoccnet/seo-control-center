<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Kernel;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\WebVitals\WebVitalRecord;
use SeoControlCenter\Modules\Forecasting\ForecastEngine;
use SeoControlCenter\Modules\Forecasting\ForecastSeries;
use SeoControlCenter\Tests\Support\Fakes\InMemoryForecasts;
use SeoControlCenter\Tests\Support\Fakes\InMemoryIndexNow;
use SeoControlCenter\Tests\Support\Fakes\InMemoryPageWatches;
use SeoControlCenter\Tests\Support\Fakes\InMemoryResearch;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Fakes\InMemoryWebVitals;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * MCP coverage of every dashboard data family: the periodic report,
 * forecasts, Core Web Vitals, page-change monitoring, browser audits, AI
 * answer samples and IndexNow, with scopes and tenant isolation.
 */
final class McpCoverageTest extends TestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $this->s->container->instance(Clock::class, new FrozenClock(new \DateTimeImmutable('2026-10-02 12:00:00 UTC')));
    }

    public function testSeoReportReturnsThePeriodFiguresAndRespectsScopes(): void
    {
        $performance = $this->s->container->get(InMemorySearchPerformance::class);
        $id = $this->s->acmeProject->id;
        $performance->upsertDailyTotals($id, [new DailyMetrics('2026-09-16', new Metrics(40, 1000, 9.0)), new DailyMetrics('2026-09-24', new Metrics(50, 1200, 8.0))]);
        $performance->replaceDay($id, '2026-09-24', ['query' => [new DimensionRow('red shoes', new Metrics(20, 300, 3.0))], 'page' => [new DimensionRow('https://shop.acme.test/shoes', new Metrics(30, 500, 4.0))]]);

        $all = $this->call($this->token(self::allScopes()), 'seo_report', ['include_daily' => true]);
        self::assertSame(['weekly', 'Week 39 · 21–27 September 2026', '2026-09-21', '2026-09-27'], [$all['frequency'], $all['label'], $all['period']['from'], $all['period']['to']]);
        self::assertSame([50, 1200], [$all['search']['combined']['clicks'], $all['search']['combined']['impressions']]);
        self::assertSame(40, $all['search']['combined_previous']['clicks']);
        self::assertSame('red shoes', $all['search']['top_queries'][0]['key']);
        self::assertCount(7, $all['search']['daily_clicks']);
        self::assertSame(0, $all['opportunities']['open']);

        $monthly = $this->call($this->token(self::allScopes()), 'seo_report', ['frequency' => 'monthly', 'to' => '2026-09-30']);
        self::assertSame('September 2026', $monthly['label']);
        self::assertStringContainsString('has not ended yet', $this->error($this->token(self::allScopes()), 'seo_report', ['to' => '2026-10-04']));
        self::assertStringContainsString('must be a Sunday', $this->error($this->token(self::allScopes()), 'seo_report', ['to' => '2026-09-23']));

        $limited = $this->call($this->token(['projects:read']), 'seo_report');
        self::assertNull($limited['search'], 'Search figures need search:read.');
        self::assertNull($limited['rankings']);
        self::assertNull($limited['opportunities']);
    }

    public function testForecastsReadStoredSeriesWithoutSaving(): void
    {
        $repo = $this->s->container->get(InMemoryForecasts::class);
        $values = [];
        for ($i = 0; $i < 170; ++$i) {
            $values[ForecastEngine::date('2026-04-10', $i)] = 20.0 + ($i % 7);
        }
        $repo->sources[$this->s->acmeProject->id] = [new ForecastSeries('google:clicks', 'Google clicks', 'g1', $values)];
        $token = $this->token(['search:read']);

        $result = $this->call($token, 'forecasts', ['horizon' => 7, 'include_points' => true]);
        self::assertSame(7, $result['horizon_days']);
        $forecast = $result['forecasts'][0];
        self::assertSame(['google:clicks', 'Google clicks'], [$forecast['series'], $forecast['label']]);
        self::assertNotNull($forecast['prediction']);
        self::assertCount(7, $forecast['points']);
        self::assertArrayNotHasKey('binding', $forecast, 'The data binding stays internal.');
        self::assertSame([], $repo->saved, 'Reading saves no forecast.');
        self::assertStringContainsString('horizon must be 7, 14 or 28', $this->error($token, 'forecasts', ['horizon' => 30]));
    }

    public function testWebVitalsListAndHistory(): void
    {
        $vitals = $this->s->container->get(InMemoryWebVitals::class);
        $id = $this->s->acmeProject->id;
        $vitals->save($id, new WebVitalRecord('https://shop.acme.test/slow', 3500.0, 210.0, 0.02, '2026-08-23', '2026-09-19', '2026-09-20 08:00:00'));
        $vitals->save($id, new WebVitalRecord('https://shop.acme.test/slow', 3100.0, 180.0, 0.02, '2026-08-30', '2026-09-26', '2026-09-27 08:00:00'));
        $vitals->save($id, new WebVitalRecord('https://shop.acme.test/fast', 1200.0, 90.0, 0.01, '2026-08-30', '2026-09-26', '2026-09-27 08:00:00'));
        $token = $this->token(['pages:read']);

        $list = $this->call($token, 'web_vitals');
        self::assertSame([2, 1], [$list['total'], $list['needing_work']]);
        $byUrl = array_column($list['pages'], 'needs_work', 'url');
        self::assertSame(['LCP'], $byUrl['https://shop.acme.test/slow']);
        self::assertSame([], $byUrl['https://shop.acme.test/fast']);
        $history = $this->call($token, 'web_vitals', ['url' => 'https://shop.acme.test/slow']);
        self::assertCount(2, $history['history']);
        self::assertSame(['LCP', 'INP'], $history['history'][1]['needs_work']);
        self::assertEquals(3100, $history['latest']['lcp_ms'], 'JSON keeps the value.');
    }

    public function testPageChangesShowWatchesAndBeforeAfterFields(): void
    {
        $watches = $this->s->container->get(InMemoryPageWatches::class);
        $id = $this->s->acmeProject->id;
        $snapshot = static fn(string $title, bool $noindex): string => (string) json_encode(['status' => 200, 'title' => $title, 'description' => 'Shoes', 'h1s' => 'Shoes', 'h1_count' => 1, 'canonical' => 'https://shop.acme.test/shoes', 'noindex' => $noindex, 'html' => true]);
        $watches->watches[$id] = [['url' => 'https://shop.acme.test/shoes', 'snapshot' => $snapshot('Red shoes', true), 'checked_at' => '2026-09-30 06:00:00', 'attempted_at' => '2026-09-30 06:00:00', 'error' => null]];
        $watches->changes[$id] = [
            ['public_id' => '01a10000-0000-7000-8000-000000000002', 'url' => 'https://shop.acme.test/shoes', 'before_snapshot' => $snapshot('Red shoes', false), 'after_snapshot' => $snapshot('Red shoes', true), 'before_at' => '2026-09-23 06:00:00', 'detected_at' => '2026-09-30 06:00:00', 'significant' => 1, 'annotation_id' => null],
            ['public_id' => '01a10000-0000-7000-8000-000000000001', 'url' => 'https://shop.acme.test/shoes', 'before_snapshot' => $snapshot('Shoes', false), 'after_snapshot' => $snapshot('Red shoes', false), 'before_at' => '2026-09-16 06:00:00', 'detected_at' => '2026-09-23 06:00:00', 'significant' => 0, 'annotation_id' => null],
        ];
        $token = $this->token(['pages:read']);

        $all = $this->call($token, 'page_changes');
        self::assertSame(2, $all['total_changes']);
        self::assertSame(['status' => 200, 'title' => 'Red shoes', 'description' => 'Shoes', 'h1s' => 'Shoes', 'h1_count' => 1, 'canonical' => 'https://shop.acme.test/shoes', 'noindex' => true], $all['watches'][0]['current']);
        self::assertSame(['noindex' => ['before' => false, 'after' => true]], $all['changes'][0]['fields']);
        self::assertTrue($all['changes'][0]['significant']);
        $significant = $this->call($token, 'page_changes', ['kind' => 'significant', 'include_watches' => false]);
        self::assertSame(1, $significant['total_changes']);
        self::assertNull($significant['watches']);
        $one = $this->call($token, 'page_changes', ['change_id' => '01a10000-0000-7000-8000-000000000001']);
        self::assertSame(['title' => ['before' => 'Shoes', 'after' => 'Red shoes']], $one['change']['fields']);
        self::assertStringContainsString('Change not found', $this->error($token, 'page_changes', ['change_id' => 'nope']));
    }

    public function testBrowserAuditAndAnswerSamplesAndIndexNow(): void
    {
        $research = $this->s->container->get(InMemoryResearch::class);
        $id = $this->s->acmeProject->id;
        $research->queue($id, $this->s->acme->id, 'rendered_audit', '', ['url' => 'https://shop.acme.test/', 'mode' => 'browser'], null);
        $research->complete($id, 'rendered_audit', '', [['url' => 'https://shop.acme.test/', 'status' => 200, 'title' => 'Shop', 'h1s' => ['Shop'], 'links' => ['https://shop.acme.test/a'], 'resources' => [], 'lcp_ms' => 2900.5, 'cls' => 0.04, 'missing_alt' => 2, 'findings' => ['Missing description'], 'task_id' => 'secret-task']]);
        $audit = $this->call($this->token(['opportunities:read']), 'browser_audit');
        self::assertSame(1, $audit['latest']['total_pages']);
        self::assertSame(['url' => 'https://shop.acme.test/', 'mode' => 'browser', 'status' => 200, 'title' => 'Shop', 'h1s' => ['Shop'], 'lcp_ms' => 2900.5, 'cls' => 0.04, 'images_missing_alt' => 2, 'links' => 1, 'loaded_resources' => 0, 'findings' => ['Missing description']], $audit['latest']['pages'][0]);
        self::assertStringNotContainsString('secret-task', (string) json_encode($audit), 'Provider task IDs stay internal.');

        $research->queue($id, $this->s->acme->id, 'llm_answers', '', ['prompts' => ['Best shoes?'], 'brand' => 'Acme', 'platforms' => ['gemini']], null);
        $research->complete($id, 'llm_answers', '', [['prompt' => 'Best shoes?', 'platform' => 'gemini', 'model' => 'gemini-2.5-flash', 'web_search' => true, 'brand_mentioned' => true, 'own_cited' => false, 'competitors' => [['domain' => 'rival.test', 'mentioned' => true, 'cited' => true]], 'citations' => [['url' => 'https://rival.test/', 'title' => 'Rival']], 'text' => str_repeat('Acme shoes are good. ', 40)]]);
        $rankings = $this->token(['rankings:read']);
        $samples = $this->call($rankings, 'ai_visibility')['answer_samples'];
        self::assertSame(['Acme', ['Best shoes?'], 1], [$samples['brand'], $samples['prompts'], $samples['total']]);
        self::assertSame([true, false, 1], [$samples['answers'][0]['brand_named'], $samples['answers'][0]['own_site_cited'], $samples['answers'][0]['cited_sources']]);
        self::assertLessThanOrEqual(301, mb_strlen($samples['answers'][0]['answer_excerpt']));
        $details = $this->call($rankings, 'ai_visibility', ['include_answer_details' => true])['answer_samples']['answers'][0];
        self::assertSame([['url' => 'https://rival.test/', 'title' => 'Rival']], $details['citations']);
        self::assertStringStartsWith('Acme shoes', $details['answer_text']);
        self::assertNull($this->call($rankings, 'ai_visibility')['crawler_access'], 'no crawler check yet');
        $this->s->container->get(\SeoControlCenter\Tests\Support\Fakes\InMemoryAiCrawlers::class)->save($this->s->acme->id, new \SeoControlCenter\Domain\AiVisibility\AiCrawlerCheck($id, 'https://shop.acme.test/robots.txt', 'ok', 200, [['token' => 'GPTBot', 'state' => 'blocked', 'named' => true, 'blocked' => []]], 'missing', 404, null, '2026-10-25 08:00:00'));
        $access = $this->call($rankings, 'ai_visibility')['crawler_access'];
        self::assertIsArray($access);
        self::assertSame([['token' => 'GPTBot', 'kind' => 'training', 'state' => 'blocked', 'own_group' => true, 'blocked_top_pages' => []]], $access['crawlers']);
        self::assertSame('missing', $access['llms_txt']['state']);
        self::assertSame(['imported' => false, 'error' => null], $this->call($this->token(['pages:read']), 'analytics_summary')['ai_referrals']);

        $indexNow = $this->s->container->get(InMemoryIndexNow::class);
        $indexNow->create($id, $this->s->acme->id, 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6');
        $indexNow->markVerified($id, 'shop.acme.test');
        $indexNow->logSubmission($id, 'manual', 3, 200, 'accepted', $this->s->owner->id);
        $monitoring = $this->call($this->token(['projects:read']), 'project_monitoring', ['kind' => 'indexnow']);
        self::assertSame([true, true, 'shop.acme.test'], [$monitoring['configured'], $monitoring['verified'], $monitoring['host']]);
        self::assertSame(3, $monitoring['submissions'][0]['url_count']);
        self::assertStringNotContainsString('a1b2c3d4e5f6', (string) json_encode($monitoring), 'The IndexNow key is not returned.');
    }

    public function testNewToolsNeedTheirScopeAndStayInsideTheTenant(): void
    {
        $byTool = ['seo_report' => [], 'forecasts' => [], 'web_vitals' => [], 'page_changes' => [], 'browser_audit' => []];
        $all = $this->token(self::allScopes());
        foreach (array_keys($byTool) as $tool) {
            self::assertSame('Project not found.', $this->error($all, $tool, [], $this->s->rivalProject->publicId), $tool);
        }
        $names = array_column($this->rpc($this->token(['projects:read']), 'tools/list')['tools'], 'name');
        self::assertContains('seo_report', $names);
        foreach (['forecasts', 'web_vitals', 'page_changes', 'browser_audit'] as $tool) {
            self::assertNotContains($tool, $names, $tool . ' is not listed without its scope.');
        }
    }

    /** @return list<string> */
    private static function allScopes(): array
    {
        return array_map(static fn(McpScope $s): string => $s->value, McpScope::cases());
    }

    /** @param list<string> $scopes */
    private function token(array $scopes): string
    {
        $service = $this->s->container->get(\SeoControlCenter\Modules\Mcp\McpTokenService::class);

        return $service->create($this->s->owner, $this->s->acme, ['name' => 'test', 'scopes' => $scopes, 'project_access' => 'all', 'projects' => [], 'expires' => '30'], \SeoControlCenter\Core\Support\ClientInfo::cli())['plaintext'];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(string $token, string $tool, array $arguments = []): array
    {
        $result = $this->rpc($token, 'tools/call', ['name' => $tool, 'arguments' => ['project_id' => $this->s->acmeProject->publicId, ...$arguments]]);
        self::assertFalse($result['isError'], $tool . ': ' . $result['content'][0]['text']);

        return $result['structuredContent'];
    }

    /** @param array<string, mixed> $arguments */
    private function error(string $token, string $tool, array $arguments, ?string $project = null): string
    {
        $result = $this->rpc($token, 'tools/call', ['name' => $tool, 'arguments' => ['project_id' => $project ?? $this->s->acmeProject->publicId, ...$arguments]]);
        self::assertTrue($result['isError'], $tool);

        return $result['content'][0]['text'];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function rpc(string $token, string $method, array $params = []): array
    {
        $response = $this->s->container->make(Kernel::class)->handle((new ServerRequest('POST', 'http://scc.test/mcp', ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer ' . $token], null, '1.1', ['REMOTE_ADDR' => '203.0.113.21']))
            ->withBody(Stream::create((string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]))));
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['result'] ?? null, (string) $response->getBody());

        return $data['result'];
    }
}
