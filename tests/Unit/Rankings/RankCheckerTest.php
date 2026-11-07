<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Rankings;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Integrations\ApiBudget;
use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Rankings\RankingSettings;
use SeoControlCenter\Domain\Rankings\RankingSyncState;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Modules\DataForSEO\Adapters\DataForSeoSerp;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\DataForSeoCredentials;
use SeoControlCenter\Modules\Rankings\Jobs\ScheduleRankChecksJob;
use SeoControlCenter\Modules\Rankings\RankChecker;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDataForSeo;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankings;

/**
 * Rank checks against a simulated SERP API: one paid request per keyword
 * and day, own-domain detection, aggregates, budgets and scheduling.
 */
final class RankCheckerTest extends TestCase
{
    private const int WORKSPACE = 1;
    private const int PROJECT = 10;
    private const string TODAY = '2026-09-26';
    private const string URL = 'https://api.dataforseo.com' . DataForSeoSerp::PATH;

    private FrozenClock $clock;

    private FakeHttpClient $http;

    private InMemoryDataForSeo $dfs;

    private InMemoryKeywords $keywords;

    private InMemoryRankings $rankings;

    private RankChecker $checker;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new \DateTimeImmutable(self::TODAY . ' 08:00:00', new \DateTimeZone('UTC')));
        $this->http = new FakeHttpClient();
        $this->dfs = new InMemoryDataForSeo($this->clock);
        $this->dfs->save(self::WORKSPACE, new DataForSeoCredentials('api@acme.test', 'secret'), 10.0, 1);
        $this->keywords = new InMemoryKeywords($this->clock);
        $this->rankings = new InMemoryRankings($this->keywords, $this->clock);
        $this->rankings->projects[self::PROJECT] = ['workspace_id' => self::WORKSPACE, 'domain' => 'acme.test'];
        $this->rankings->saveSettings(self::PROJECT, new RankingSettings(true, SerpDevice::Mobile, RankingSettings::DAILY), 1);
        $client = new DataForSeoClient($this->http, $this->dfs, $this->dfs, $this->dfs, $this->clock, new NullLogger(), false, 7);
        $this->checker = new RankChecker($this->rankings, new DataForSeoSerp($client, $this->dfs, $this->clock), new NullLogger());

        // acme.test ranks at position = number of words in the keyword; "unknown x y z" does not rank.
        $this->http->on('POST', self::URL, static function (string $url, array $headers, ?string $body): HttpResponse {
            $keyword = json_decode((string) $body, true)[0]['keyword'];
            $items = [['type' => 'organic', 'rank_group' => 1, 'rank_absolute' => 1, 'domain' => 'rival.test', 'url' => 'https://rival.test/']];
            if (!str_starts_with($keyword, 'unknown')) {
                $items[] = ['type' => 'organic', 'rank_group' => str_word_count($keyword), 'rank_absolute' => 9, 'domain' => 'www.acme.test', 'url' => 'https://www.acme.test/' . str_replace(' ', '-', $keyword)];
            }

            return new HttpResponse(200, (string) json_encode(['status_code' => 20000, 'cost' => 0.002, 'tasks' => [['id' => 't', 'status_code' => 20000, 'result' => [['items' => $items]]]]]));
        });
    }

    public function testChecksEachKeywordOncePerDayAndAggregates(): void
    {
        $this->keywords->add(self::PROJECT, ['red shoes', 'buy red running shoes', 'unknown x y z'], KeywordLocale::default(), 1);

        self::assertTrue($this->checker->run(self::PROJECT, self::WORKSPACE, self::TODAY, static fn(): bool => true));

        self::assertCount(3, $this->http->requests);
        self::assertSame('mobile', json_decode((string) $this->http->requests[0]['body'], true)[0]['device']);
        $day = $this->rankings->latestDay(self::PROJECT, SerpDevice::Mobile);
        self::assertNotNull($day);
        self::assertSame(3, $day->keywords);
        self::assertSame(2, $day->ranked);
        self::assertSame(1, $day->top3);
        self::assertSame(3.0, $day->averagePosition);
        $sync = $this->rankings->syncState(self::PROJECT);
        self::assertSame(RankingSyncState::OK, $sync?->status);
        self::assertSame(self::TODAY, $sync->lastCheckedOn);

        $ranked = $this->rankings->keywords(self::PROJECT, self::WORKSPACE, SerpDevice::Mobile, 10, 0);
        self::assertSame(2, $ranked[0]->position);
        self::assertSame('https://www.acme.test/red-shoes', $ranked[0]->url);
        self::assertNull($ranked[2]->position);

        // A second run the same day pays for nothing.
        $this->checker->run(self::PROJECT, self::WORKSPACE, self::TODAY, static fn(): bool => true);
        self::assertCount(3, $this->http->requests);
        self::assertCount(3, $this->dfs->usage);
    }

    public function testStopsWithinTheTimeBudgetAndContinuesLater(): void
    {
        $this->keywords->add(self::PROJECT, ['a b', 'c d', 'e f'], KeywordLocale::default(), 1);
        $calls = 0;

        self::assertFalse($this->checker->run(self::PROJECT, self::WORKSPACE, self::TODAY, static function () use (&$calls): bool {
            return ++$calls <= 2;
        }));
        self::assertCount(2, $this->http->requests);
        self::assertSame(2, $this->rankings->latestDay(self::PROJECT, SerpDevice::Mobile)?->keywords, 'Partial days are aggregated.');

        self::assertTrue($this->checker->run(self::PROJECT, self::WORKSPACE, self::TODAY, static fn(): bool => true));
        self::assertCount(3, $this->http->requests);
    }

    public function testNothingIsSpentWhenTrackingIsOffOrTheProjectIsElsewhere(): void
    {
        $this->keywords->add(self::PROJECT, ['red shoes'], KeywordLocale::default(), 1);

        self::assertTrue($this->checker->run(self::PROJECT, 99, self::TODAY, static fn(): bool => true), 'Wrong workspace.');
        $this->rankings->saveSettings(self::PROJECT, RankingSettings::default(), 1);
        self::assertTrue($this->checker->run(self::PROJECT, self::WORKSPACE, self::TODAY, static fn(): bool => true), 'Tracking off.');

        self::assertSame([], $this->http->requests);
    }

    public function testBudgetStopIsReported(): void
    {
        $this->dfs->saveBudget(self::WORKSPACE, DataForSeoClient::PROVIDER, new ApiBudget(dailyLimit: 0.021), 1);
        $this->keywords->add(self::PROJECT, ['a b', 'c d'], KeywordLocale::default(), 1);

        try {
            $this->checker->run(self::PROJECT, self::WORKSPACE, self::TODAY, static fn(): bool => true);
            self::fail('Expected the budget to stop the check.');
        } catch (BudgetExceededException) {
        }

        self::assertCount(1, $this->http->requests, 'The first check (estimated $0.02, charged $0.002) fits; the second estimate would pass the limit.');
        self::assertStringContainsString('daily budget', (string) $this->rankings->syncState(self::PROJECT)?->lastError);
        self::assertSame(1, $this->rankings->latestDay(self::PROJECT, SerpDevice::Mobile)?->keywords);
    }

    public function testSchedulerQueuesDueProjectsOnly(): void
    {
        $this->rankings->projects[11] = ['workspace_id' => self::WORKSPACE, 'domain' => 'weekly.test'];
        $this->rankings->saveSettings(11, new RankingSettings(true, SerpDevice::Desktop, RankingSettings::WEEKLY), 1);
        $this->rankings->setSyncState(11, RankingSyncState::OK, checkedOn: '2026-09-22');
        $this->rankings->projects[12] = ['workspace_id' => self::WORKSPACE, 'domain' => 'off.test'];
        $this->rankings->saveSettings(12, RankingSettings::default(), 1);

        $job = new ScheduleRankChecksJob($this->rankings, $this->rankings, $this->clock);
        $job->handle([], new JobContext('job', ScheduleRankChecksJob::TYPE, 1, 1, static fn(): bool => true, time() + 50));

        self::assertSame([['project_id' => self::PROJECT, 'workspace_id' => self::WORKSPACE, 'date' => self::TODAY]], $this->rankings->requests);
    }
}
