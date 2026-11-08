<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Kernel;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdea;
use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Keywords\Markets;
use SeoControlCenter\Domain\Opportunities\BusinessContext;
use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Modules\ArticleIdeas\ArticleIdeaService;
use SeoControlCenter\Tests\Support\Fakes\FakeResearchProvider;
use SeoControlCenter\Tests\Support\Fakes\InMemoryArticleIdeas;
use SeoControlCenter\Tests\Support\Fakes\InMemoryBusinessContexts;
use SeoControlCenter\Tests\Support\Fakes\InMemoryCompetitors;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;
use SeoControlCenter\Tests\Support\Scenario;

/** Research → Article ideas across the markets a project targets. */
final class ArticleIdeasMarketsHttpTest extends TestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        $this->s = new Scenario();
    }

    public function testMarketsComeFromTheProfileThenKeywordsByCountThenTheDefaultAndAreCapped(): void
    {
        $project = $this->s->acmeProject;
        $plan = $this->service()->marketPlan($project);
        self::assertSame([ArticleIdeaService::MARKETS_DEFAULT, ['2840:en']], [$plan['source'], self::keys($plan['markets'])]);

        $keywords = $this->s->container->get(InMemoryKeywords::class);
        self::assertInstanceOf(InMemoryKeywords::class, $keywords);
        $keywords->add($project->id, ['tomato seeds'], new KeywordLocale(2840, 'en'), $this->s->owner->id);
        $keywords->add($project->id, ['tomatensamen'], new KeywordLocale(2276, 'de'), $this->s->owner->id);
        $keywords->add($project->id, ['pepper seeds', 'sunflower seeds', 'seed starter kit'], new KeywordLocale(2124, 'en'), $this->s->owner->id);
        $keywords->add($project->id, ['paprikasamen'], new KeywordLocale(2276, 'de'), $this->s->owner->id);
        $keywords->add($project->id, ['heirloom tomatoes'], new KeywordLocale(2826, 'en'), $this->s->owner->id);
        $plan = $this->service()->marketPlan($project);
        self::assertSame(ArticleIdeaService::MARKETS_KEYWORDS, $plan['source']);
        self::assertSame(['2124:en', '2276:de', '2840:en', '2826:en'], self::keys($plan['markets']), 'Most keywords first; ties keep the market added first. The newest keyword no longer decides.');

        $this->profile(['2826:en', '2840:en']);
        $plan = $this->service()->marketPlan($project);
        self::assertSame([ArticleIdeaService::MARKETS_PROFILE, ['2826:en', '2840:en']], [$plan['source'], self::keys($plan['markets'])]);

        $many = [];
        foreach (array_keys(Markets::LABS) as $country) {
            $many[] = $country . ':' . Markets::LABS[$country][0];
        }
        self::assertGreaterThan(BusinessContext::MAX_MARKETS, count($many));
        $this->profile($many);
        $plan = $this->service()->marketPlan($project);
        self::assertCount(BusinessContext::MAX_MARKETS, [...$plan['markets'], ...$plan['skipped']]);
        self::assertSame(array_slice($many, 0, BusinessContext::MAX_MARKETS), self::keys($plan['markets']));
    }

    public function testOneLookupPerCompetitorAndMarketConfirmedAtTheShownCostWithUnsupportedMarketsSkipped(): void
    {
        $this->competitors()->add($this->s->acmeProject->id, 'rival.test', Competitor::MANUAL, $this->s->owner->id);
        // Germany · English: DataForSEO Labs has no data for it.
        $this->profile(['2840:en', '2124:en', '2276:en', '2276:de']);
        $owner = $this->s->loggedIn($this->s->owner);
        $html = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('Markets: United States · English, Canada · English, Germany · German.', $html);
        self::assertStringContainsString('Markets from your business profile.', $html);
        self::assertStringContainsString('/projects/' . $this->s->acmeProject->publicId . '/opportunities/business', $html);
        self::assertStringContainsString('Skipped, because DataForSEO Labs has no competitor rankings for them: Germany · English.', $html);
        $lookup = $this->service()->lookupCost();
        self::assertEqualsWithDelta(0.072, $lookup, 1e-9);
        self::assertStringContainsString('Cost: 1 competitor × 3 markets × about $0.07 per lookup = about $0.22 in total. Per market: United States · English $0.07 · Canada · English $0.07 · Germany · German $0.07.', $html);
        self::assertStringContainsString('name="confirmed_cost" value="0.22"', $html);
        self::assertSame([], AccessibilityTest::problems($html, true));

        $owner->post($this->url(), ['confirmed_cost' => '0.22']);
        self::assertStringContainsString('(about $0.22 for 1 competitor in 3 markets). Confirm the paid run.', (string) $owner->get($this->url())->getBody());
        $owner->post($this->url(), ['confirm_paid' => '1', 'confirmed_cost' => '0.07']);
        self::assertStringContainsString('more than the $0.07 you confirmed', (string) $owner->get($this->url())->getBody());
        self::assertSame([], $this->ideas()->queued, 'Nothing is bought without confirming the full cost.');

        $owner->post($this->url(), ['confirm_paid' => '1', 'confirmed_cost' => '0.22']);
        self::assertStringContainsString('Skipped, because DataForSEO Labs has no data for them: Germany · English.', (string) $owner->get($this->url())->getBody());
        $run = $this->ideas()->runs[$this->ideas()->queued[0]['run_id']];
        self::assertSame(['2840:en', '2124:en', '2276:de'], $run->markets);
        self::assertSame('2840:en', $run->market);
        self::assertEqualsWithDelta(3 * $lookup, $run->estimatedCost, 1e-9);

        $this->provider()->rankedIn = [
            'rival.test|2840:en' => [self::kw('how to grow tomatoes', 900, 'https://rival.test/blog/grow-tomatoes'), self::kw('pepper plant spacing', 300, 'https://rival.test/blog/pepper-spacing')],
            'rival.test|2124:en' => [self::kw('how to grow tomatoes', 200, 'https://rival.test/ca/blog/grow-tomatoes')],
            'rival.test|2276:de' => [self::kw('tomaten pflanzen anleitung', 700, 'https://rival.test/de/blog/tomaten-pflanzen')],
        ];
        $this->service()->execute($run->id);
        self::assertSame([['rival.test', '2840:en', 500], ['rival.test', '2124:en', 500], ['rival.test', '2276:de', 500]], array_column($this->provider()->calls, 'args'), 'One lookup per competitor and market; the skipped market is never bought.');
        self::assertSame(['2840:en', '2840:en', '2124:en', '2276:de'], array_column($this->ideas()->rows[$run->id], 'market'));

        $ideas = $this->ideas()->ideas[$this->s->acmeProject->id]['ideas'];
        $tomatoes = array_values(array_filter($ideas, static fn(ArticleIdea $i): bool => $i->mainKeyword === 'how to grow tomatoes'))[0];
        self::assertSame(['2840:en', '2124:en'], $tomatoes->marketKeys(), 'One idea for the same search in two English markets.');
        self::assertSame(1100, $tomatoes->volume);
        self::assertSame(hash('sha256', 'how to grow tomatoes'), $tomatoes->key);

        $html = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('<option value="2276:de">Germany · German</option>', $html);
        self::assertStringContainsString('<option value="">All markets</option>', $html);
        self::assertStringContainsString('1,100 a month in total across 2 markets', $html);
        self::assertStringContainsString('Searched in 2 markets: United States · English 900, Canada · English 200 a month.', $html);
        self::assertStringContainsString('· United States · English, Canada · English</p>', $html, 'Each idea names its markets.');
        self::assertStringContainsString('Tomaten pflanzen anleitung', $html);
        self::assertSame([], AccessibilityTest::problems($html, true));

        $german = (string) $owner->get($this->url() . '?market=2276:de')->getBody();
        self::assertStringContainsString('Tomaten pflanzen anleitung', $german);
        self::assertStringNotContainsString('How to grow tomatoes', $german);
        self::assertStringContainsString('<option value="2276:de" selected>', $german);
        self::assertStringContainsString('How to grow tomatoes', (string) $owner->get($this->url() . '?market=nowhere')->getBody(), 'An unknown filter shows all.');

        // The brief from an idea carries its markets.
        $seed = $this->service()->briefSeed($this->s->acmeProject, $tomatoes->key);
        self::assertContains('Markets: United States · English (900 a month), Canada · English (200 a month)', $seed['evidence'] ?? []);

        // The free rebuild uses the stored rows of every market and buys nothing.
        $calls = count($this->provider()->calls);
        unset($this->ideas()->ideas[$this->s->acmeProject->id]);
        self::assertSame(3, $this->service()->rebuild($this->s->acmeProject->id, $this->s->acme->id));
        self::assertCount($calls, $this->provider()->calls);
        self::assertSame(['2840:en', '2124:en'], $this->ideas()->idea($this->s->acmeProject, $tomatoes->key)?->marketKeys());

        // Marking it as published keeps its markets with the prediction.
        $owner->get($this->url());
        $owner->post($this->url() . '/' . $tomatoes->key . '/published', ['url' => 'https://shop.acme.test/blog/grow-tomatoes', 'published_on' => '2026-11-01']);
        $tracked = $this->s->container->make(\SeoControlCenter\Modules\ArticleIdeas\ArticleTrackingService::class)->published($this->s->acmeProject);
        self::assertCount(1, $tracked);
        self::assertSame(['2840:en', '2124:en'], $tracked[0]->present('2026-11-08')['markets']);
    }

    public function testSingleMarketProjectsLookAsBeforeAndOldRunsStillRender(): void
    {
        $this->competitors()->add($this->s->acmeProject->id, 'rival.test', Competitor::MANUAL, $this->s->owner->id);
        $owner = $this->s->loggedIn($this->s->owner);
        $html = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('Market: United States · English.', $html);
        self::assertStringNotContainsString('Cost:', $html);
        self::assertStringNotContainsString('All markets', $html);
        self::assertStringContainsString('to DataForSEO for these competitors\' rankings</label>', $html);

        // A run saved before markets: one market column, rows without a market.
        $ideas = $this->ideas();
        $run = $ideas->createRun($this->s->acmeProject, [['domain' => 'rival.test', 'found' => false]], '2124:en', 0.07, $this->s->owner->id, '2026-10-01 10:00:00');
        $ideas->saveRows($run->id, $this->s->acmeProject->id, [['domain' => 'rival.test', 'found' => false, 'url' => 'https://rival.test/blog/pepper-spacing', 'keyword' => 'pepper plant spacing', 'volume' => 300, 'difficulty' => 10, 'position' => 3, 'traffic' => 30.0, 'avg_rd' => 2]]);
        $ideas->finish($run->id, '2026-10-01 10:05:00');
        self::assertSame(['2124:en'], $run->marketKeys());

        // Ideas stored before markets have none: they show as the run's market.
        $old = ArticleIdea::fromArray(['idea_key' => hash('sha256', 'pepper plant spacing'), 'kind' => 'new', 'main_keyword' => 'pepper plant spacing', 'volume' => 300, 'keywords' => [['keyword' => 'pepper plant spacing', 'volume' => 300, 'difficulty' => 10]], 'competitors' => [], 'your_page' => null, 'prediction' => ['traffic' => ['low' => 6, 'expected' => 15, 'high' => 45], 'difficulty' => ['level' => 'low', 'reasons' => []], 'backlinks' => null, 'months' => ['low' => 1, 'high' => 3, 'basis' => 'assumption']], 'score' => 15.0, 'reasons' => ['Old reason.']]);
        self::assertSame([], $old->markets);
        $ideas->replaceIdeas($this->s->acmeProject->id, $this->s->acme->id, $run->id, [$old], '2026-10-01 10:05:00');
        $html = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('Pepper plant spacing', $html);
        self::assertStringContainsString('300 a month in total. Old reason.', $html);
        self::assertSame(['2124:en'], $this->service()->overview($this->s->owner, $this->s->acmeProject)['ideas'][0]->marketKeys());

        // Its free rebuild treats the rows as the run's market.
        $calls = count($this->provider()->calls);
        self::assertSame(1, $this->service()->rebuild($this->s->acmeProject->id, $this->s->acme->id));
        self::assertSame(['2124:en'], $ideas->idea($this->s->acmeProject, hash('sha256', 'pepper plant spacing'))?->marketKeys());
        self::assertCount($calls, $this->provider()->calls);
    }

    public function testMcpToolReturnsTheMarketsAndEachIdeasMarket(): void
    {
        $this->competitors()->add($this->s->acmeProject->id, 'rival.test', Competitor::MANUAL, $this->s->owner->id);
        $this->profile(['2840:en', '2276:de']);
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url(), ['confirm_paid' => '1']);
        $this->provider()->rankedIn = [
            'rival.test|2840:en' => [self::kw('how to grow tomatoes', 900, 'https://rival.test/blog/grow-tomatoes')],
            'rival.test|2276:de' => [self::kw('tomaten pflanzen anleitung', 700, 'https://rival.test/de/blog/tomaten-pflanzen')],
        ];
        $this->service()->execute($this->ideas()->queued[0]['run_id']);

        $token = $this->s->container->make(\SeoControlCenter\Modules\Mcp\McpTokenService::class)->create($this->s->owner, $this->s->acme, ['name' => 'test', 'scopes' => ['keywords:read'], 'project_access' => 'all', 'expires' => '30'], ClientInfo::cli())['plaintext'];
        $response = $this->s->container->make(Kernel::class)->handle(
            (new ServerRequest('POST', 'http://scc.test/mcp', ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $token], null, '1.1', ['REMOTE_ADDR' => '203.0.113.22']))
                ->withBody(Stream::create((string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'article_ideas', 'arguments' => ['project_id' => $this->s->acmeProject->publicId]]]))),
        );
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        $out = $data['result']['structuredContent'] ?? null;
        self::assertIsArray($out);

        self::assertSame('United States · English, Germany · German', $out['market'], 'The old field stays, as one label.');
        self::assertSame(['2840:en', '2276:de'], array_column($out['markets'], 'market'));
        self::assertSame(['United States · English', 'Germany · German'], array_column($out['markets'], 'label'));
        self::assertSame('profile', $out['market_source']);
        self::assertSame([], $out['skipped_markets']);
        self::assertSame(['2840:en', '2276:de'], $out['last_lookup']['markets']);
        $byKeyword = array_column($out['ideas'], null, 'main_keyword');
        self::assertSame('United States · English', $byKeyword['how to grow tomatoes']['market']);
        self::assertSame('Germany · German', $byKeyword['tomaten pflanzen anleitung']['market']);
        self::assertSame([['market' => '2276:de', 'volume' => 700, 'label' => 'Germany · German']], array_map(static fn(array $m): array => ['market' => $m['market'], 'volume' => $m['volume'], 'label' => $m['label']], $byKeyword['tomaten pflanzen anleitung']['markets']));
    }

    /** @param list<string> $markets */
    private function profile(array $markets): void
    {
        $business = $this->s->container->get(InMemoryBusinessContexts::class);
        self::assertInstanceOf(InMemoryBusinessContexts::class, $business);
        $business->save($this->s->acmeProject->id, new BusinessContext('ecommerce', 'sales', markets: $markets), $this->s->owner->id);
    }

    /**
     * @param list<KeywordLocale> $locales
     * @return list<string>
     */
    private static function keys(array $locales): array
    {
        return array_map(static fn(KeywordLocale $l): string => $l->key(), $locales);
    }

    private static function kw(string $keyword, int $volume, string $url): ResearchKeyword
    {
        return new ResearchKeyword($keyword, $volume, null, 20, null, 3, $url, $volume * 0.1, null, 4);
    }

    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/article-ideas';
    }

    private function ideas(): InMemoryArticleIdeas
    {
        $ideas = $this->s->container->get(InMemoryArticleIdeas::class);
        self::assertInstanceOf(InMemoryArticleIdeas::class, $ideas);

        return $ideas;
    }

    private function competitors(): InMemoryCompetitors
    {
        $competitors = $this->s->container->get(InMemoryCompetitors::class);
        self::assertInstanceOf(InMemoryCompetitors::class, $competitors);

        return $competitors;
    }

    private function provider(): FakeResearchProvider
    {
        $provider = $this->s->container->get(FakeResearchProvider::class);
        self::assertInstanceOf(FakeResearchProvider::class, $provider);

        return $provider;
    }

    private function service(): ArticleIdeaService
    {
        return $this->s->container->make(ArticleIdeaService::class);
    }
}
