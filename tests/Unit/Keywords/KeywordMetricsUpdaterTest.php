<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Keywords;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Integrations\ApiBudget;
use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Keywords\KeywordSyncState;
use SeoControlCenter\Modules\DataForSEO\Adapters\DataForSeoKeywordMetrics;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\DataForSeoCredentials;
use SeoControlCenter\Modules\Keywords\KeywordMetricsUpdater;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDataForSeo;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;

/**
 * Metrics updates: only missing/outdated keywords are paid for, one request
 * per locale, empty results are remembered, budgets and errors are reported.
 */
final class KeywordMetricsUpdaterTest extends TestCase
{
    private const int WORKSPACE = 1;
    private const int PROJECT = 10;
    private const string URL = 'https://api.dataforseo.com' . DataForSeoKeywordMetrics::PATH;

    private FrozenClock $clock;

    private FakeHttpClient $http;

    private InMemoryDataForSeo $dfs;

    private InMemoryKeywords $keywords;

    private KeywordMetricsUpdater $updater;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-09-26 12:00:00', new \DateTimeZone('UTC')));
        $this->http = new FakeHttpClient();
        $this->dfs = new InMemoryDataForSeo($this->clock);
        $this->dfs->save(self::WORKSPACE, new DataForSeoCredentials('api@acme.test', 'secret'), 10.0, 1);
        $this->keywords = new InMemoryKeywords($this->clock);
        $client = new DataForSeoClient($this->http, $this->dfs, $this->dfs, $this->dfs, $this->clock, new NullLogger(), false, 7);
        $this->updater = new KeywordMetricsUpdater($this->keywords, new DataForSeoKeywordMetrics($client, $this->dfs), $this->clock, new NullLogger());

        // Echoes volumes for every requested keyword except "unknown phrase".
        $this->http->on('POST', self::URL, static function (string $url, array $headers, ?string $body): HttpResponse {
            $task = json_decode((string) $body, true)[0];
            $result = [];
            foreach ($task['keywords'] as $keyword) {
                if ($keyword !== 'unknown phrase') {
                    $result[] = ['keyword' => $keyword, 'search_volume' => strlen($keyword) * 100, 'cpc' => 0.5, 'competition' => 'LOW', 'competition_index' => 10];
                }
            }

            return new HttpResponse(200, (string) json_encode(['status_code' => 20000, 'cost' => 0.075, 'tasks' => [['id' => 't', 'status_code' => 20000, 'result' => $result]]]));
        });
    }

    public function testFetchesOncePerLocaleAndRemembersKeywordsWithoutData(): void
    {
        $this->keywords->add(self::PROJECT, ['red shoes', 'unknown phrase'], new KeywordLocale(2840, 'en'), 1);
        $this->keywords->add(self::PROJECT, ['rote schuhe'], new KeywordLocale(2276, 'de'), 1);

        self::assertTrue($this->updater->run(self::PROJECT, self::WORKSPACE, static fn(): bool => true));

        self::assertCount(2, $this->http->requests, 'One request per locale.');
        self::assertSame(KeywordSyncState::OK, $this->keywords->syncState(self::PROJECT)?->status);
        $page = $this->keywords->page(self::PROJECT, self::WORKSPACE, 10, 0);
        self::assertSame('rote schuhe', $page[0]->keyword);
        self::assertSame(1100, $page[0]->metrics?->searchVolume);
        self::assertNotNull($page[2]->metrics, 'A keyword without data is stored empty.');
        self::assertNull($page[2]->metrics->searchVolume);

        // Nothing is outdated: a second run sends no paid request.
        $this->updater->run(self::PROJECT, self::WORKSPACE, static fn(): bool => true);
        self::assertCount(2, $this->http->requests);

        // After the refresh window, metrics are fetched again.
        $this->clock->advance('+31 days');
        $this->updater->run(self::PROJECT, self::WORKSPACE, static fn(): bool => true);
        self::assertCount(4, $this->http->requests);
    }

    public function testStopsWhenTheTimeBudgetIsUsed(): void
    {
        $this->keywords->add(self::PROJECT, ['red shoes'], KeywordLocale::default(), 1);

        self::assertFalse($this->updater->run(self::PROJECT, self::WORKSPACE, static fn(): bool => false));
        self::assertSame([], $this->http->requests);
    }

    public function testBudgetStopIsReportedOnTheProject(): void
    {
        $this->dfs->saveBudget(self::WORKSPACE, DataForSeoClient::PROVIDER, new ApiBudget(dailyLimit: 0.05), 1);
        $this->keywords->add(self::PROJECT, ['red shoes'], KeywordLocale::default(), 1);

        try {
            $this->updater->run(self::PROJECT, self::WORKSPACE, static fn(): bool => true);
            self::fail('Expected the budget to stop the update.');
        } catch (BudgetExceededException) {
        }

        self::assertSame([], $this->http->requests);
        $sync = $this->keywords->syncState(self::PROJECT);
        self::assertSame(KeywordSyncState::ERROR, $sync?->status);
        self::assertStringContainsString('daily budget', (string) $sync->lastError);
    }

    public function testWorkspaceWithoutDataForSeoIsReportedWithoutRequests(): void
    {
        $this->keywords->add(self::PROJECT, ['red shoes'], KeywordLocale::default(), 1);

        self::assertTrue($this->updater->run(self::PROJECT, 2, static fn(): bool => true));

        self::assertSame([], $this->http->requests);
        self::assertStringContainsString('not connected', (string) $this->keywords->syncState(self::PROJECT)?->lastError);
    }
}
