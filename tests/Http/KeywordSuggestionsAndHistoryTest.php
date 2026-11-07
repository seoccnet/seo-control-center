<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Rankings\RankingSettings;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankings;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Keyword suggestions from Search Console, the position filter and the
 * per-keyword ranking history page.
 */
final class KeywordSuggestionsAndHistoryTest extends TestCase
{
    private Scenario $s;

    private InMemoryKeywords $keywords;

    private InMemoryRankings $rankings;

    private InMemorySearchPerformance $searchConsole;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $keywords = $this->s->container->get(InMemoryKeywords::class);
        $rankings = $this->s->container->get(InMemoryRankings::class);
        $searchConsole = $this->s->container->get(InMemorySearchPerformance::class);
        self::assertInstanceOf(InMemoryKeywords::class, $keywords);
        self::assertInstanceOf(InMemoryRankings::class, $rankings);
        self::assertInstanceOf(InMemorySearchPerformance::class, $searchConsole);
        $this->keywords = $keywords;
        $this->rankings = $rankings;
        $this->searchConsole = $searchConsole;

        // Two days of Search Console queries for Acme Shop.
        $project = $this->s->acmeProject->id;
        $this->searchConsole->upsertDailyTotals($project, [new DailyMetrics('2026-09-20', new Metrics(10, 500, 8.0)), new DailyMetrics('2026-09-21', new Metrics(12, 600, 7.0))]);
        foreach (['2026-09-20', '2026-09-21'] as $day) {
            $this->searchConsole->replaceDay($project, $day, ['query' => [
                new DimensionRow('red shoes', new Metrics(5, 100, 4.0)),
                new DimensionRow('Blue  Shoes', new Metrics(3, 80, 9.0)),
                new DimensionRow('what? shoes', new Metrics(1, 10, 30.0)),
            ]]);
        }
    }

    public function testSuggestsUntrackedQueriesAndAddsSelectedOnes(): void
    {
        $this->keywords->add($this->s->acmeProject->id, ['red shoes'], KeywordLocale::default(), $this->s->owner->id);
        $browser = $this->s->loggedIn($this->s->owner);

        $html = (string) $browser->get($this->keywordsUrl())->getBody();

        self::assertStringContainsString('Suggestions from Search Console', $html);
        self::assertStringContainsString('value="blue shoes"', $html, 'Suggestions are normalized.');
        self::assertStringNotContainsString('value="red shoes"', $html, 'Tracked keywords are not suggested.');
        self::assertStringNotContainsString('what? shoes', $html, 'Queries that are not valid keywords are skipped.');

        $response = $browser->post($this->keywordsUrl(), ['suggested' => ['blue shoes'], 'location' => '2276', 'language' => 'de']);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame(['red shoes', 'blue shoes'], $this->keywords->texts($this->s->acmeProject->id));
        self::assertStringNotContainsString('Suggestions from Search Console', (string) $browser->get($this->keywordsUrl())->getBody(), 'Nothing left to suggest.');
    }

    public function testViewersSeeNoSuggestions(): void
    {
        self::assertStringNotContainsString('Suggestions from Search Console', (string) $this->s->loggedIn($this->s->viewer)->get($this->keywordsUrl())->getBody());
    }

    public function testFilterAndKeywordHistory(): void
    {
        $project = $this->s->acmeProject->id;
        $this->keywords->add($project, ['red shoes', 'blue shoes'], KeywordLocale::default(), $this->s->owner->id);
        $this->rankings->saveSettings($project, new RankingSettings(true, SerpDevice::Desktop, RankingSettings::DAILY), $this->s->owner->id);
        [$red, $blue] = $this->keywords->keywords[$project];
        $top = [['position' => 1, 'domain' => 'rival.test', 'url' => 'https://rival.test/']];
        foreach (['2026-09-19' => [7, null], '2026-09-26' => [2, 40]] as $day => [$redPos, $bluePos]) {
            $this->rankings->storeSnapshot(new RankSnapshot($red['id'], $project, SerpDevice::Desktop, $day, $redPos, $redPos, 'https://shop.acme.test/red', ['featured_snippet'], $top));
            $this->rankings->storeSnapshot(new RankSnapshot($blue['id'], $project, SerpDevice::Desktop, $day, $bluePos, $bluePos, null, [], []));
            $this->rankings->aggregate($project, $this->s->acme->id, SerpDevice::Desktop, $day);
        }
        $browser = $this->s->loggedIn($this->s->viewer);

        $top3 = (string) $browser->get($this->rankingsUrl() . '?filter=top3')->getBody();
        self::assertStringContainsString('>red shoes</a>', $top3);
        self::assertStringNotContainsString('>blue shoes</a>', $top3);
        self::assertStringContainsString('>blue shoes</a>', (string) $browser->get($this->rankingsUrl() . '?filter=21-100')->getBody());

        $html = (string) $browser->get($this->rankingsUrl() . '/' . $red['public_id'])->getBody();
        self::assertStringContainsString('Position history', $html);
        self::assertStringContainsString('https://shop.acme.test/red', $html);
        self::assertStringContainsString('featured snippet', $html);
        self::assertStringContainsString('rival.test', $html);
        self::assertStringContainsString('200 impressions', $html, 'Search Console totals of the exact query.');
        self::assertMatchesRegularExpression('#Best \(12 months\)</h2>\s*<p class="stat-value">2<#', $html);
    }

    public function testHistoryOfUnknownOrForeignKeywordIsNotFound(): void
    {
        $this->keywords->add($this->s->acmeProject->id, ['red shoes'], KeywordLocale::default(), $this->s->owner->id);
        $keyword = $this->keywords->keywords[$this->s->acmeProject->id][0]['public_id'];

        self::assertSame(404, $this->s->loggedIn($this->s->owner)->get($this->rankingsUrl() . '/0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f99')->getStatusCode());
        self::assertSame(404, $this->s->loggedIn($this->s->rivalOwner)->get($this->rankingsUrl() . '/' . $keyword)->getStatusCode());
        self::assertSame(
            404,
            $this->s->loggedIn($this->s->rivalOwner)->get('/projects/' . $this->s->rivalProject->publicId . '/rankings/' . $keyword)->getStatusCode(),
            'A keyword ID from another project is not found through one\'s own project.',
        );
    }

    private function keywordsUrl(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/keywords';
    }

    private function rankingsUrl(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/rankings';
    }
}
