<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\SerpLandscape;
use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Modules\Research\ResearchService;
use SeoControlCenter\Tests\Support\Fakes\FakeDomainStrengthProvider;
use SeoControlCenter\Tests\Support\Fakes\FakeResearchProvider;
use SeoControlCenter\Tests\Support\Fakes\FakeSeedSuggester;
use SeoControlCenter\Tests\Support\Fakes\InMemoryBusinessContexts;
use SeoControlCenter\Tests\Support\Fakes\InMemoryOpportunities;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankings;
use SeoControlCenter\Tests\Support\Fakes\InMemoryResearch;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Fakes\InMemorySerpLandscapes;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Business profile, BID and zero-click on the findings, keyword ratings,
 * the on-request competitor-strength check, AI seed suggestions and the
 * seed-and-modifier lookup: permissions and tenant isolation included.
 */
final class OpportunityIntelligenceHttpTest extends TestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        $this->s = new Scenario(clock: new \SeoControlCenter\Core\Support\FrozenClock(new \DateTimeImmutable('2026-10-03')));
        $rankings = $this->s->container->get(InMemoryRankings::class);
        $searchConsole = $this->s->container->get(InMemorySearchPerformance::class);
        self::assertInstanceOf(InMemoryRankings::class, $rankings);
        self::assertInstanceOf(InMemorySearchPerformance::class, $searchConsole);
        $rankings->projects[$this->s->acmeProject->id] = ['workspace_id' => $this->s->acme->id, 'domain' => 'shop.acme.test'];
        $project = $this->s->acmeProject->id;
        $searchConsole->upsertDailyTotals($project, [new DailyMetrics('2026-10-01', new Metrics(10, 2000, 8.0))]);
        $searchConsole->replaceDay($project, '2026-10-01', [
            'query' => [
                new DimensionRow('what is a running shoe', new Metrics(4, 1000, 2.0)),
                new DimensionRow('buy running shoes', new Metrics(0, 400, 34.0)),
            ],
            'page' => [],
        ]);
    }

    public function testBusinessProfileScoresFindingsAndTeamRatingsOverrideIt(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $page = (string) $owner->get($this->url() . '/business')->getBody();
        self::assertStringContainsString('What is a search worth to you?', $page);
        self::assertSame(422, $owner->post($this->url() . '/business', ['model' => 'castle', 'goal' => 'sales'])->getStatusCode());
        self::assertSame(303, $owner->post($this->url() . '/business', ['model' => 'ecommerce', 'goal' => 'sales', 'description' => 'Running shoes online', 'offerings' => "running shoes\nsocks", 'max_difficulty' => '35'])->getStatusCode());
        $profiles = $this->s->container->get(InMemoryBusinessContexts::class);
        self::assertInstanceOf(InMemoryBusinessContexts::class, $profiles);
        self::assertSame(['running shoes', 'socks'], $profiles->find($this->s->acmeProject->id)?->offerings);

        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/analyze')->getStatusCode());
        $html = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('Business profile', $html);
        self::assertStringContainsString('BID verdict', $html);
        self::assertStringContainsString('Zero-click risk', $html);
        self::assertStringContainsString('Business potential of “buy running shoes”', $html, 'Editors can rate each keyword.');

        $opportunities = $this->s->container->get(InMemoryOpportunities::class);
        self::assertInstanceOf(InMemoryOpportunities::class, $opportunities);
        $byQuery = [];
        foreach ($opportunities->catalog($this->s->acmeProject->id)['rows'] as $row) {
            $byQuery[(string) $row->keyword()] = $row;
        }
        self::assertSame(3, $byQuery['buy running shoes']->businessPotential());
        self::assertSame(1, $byQuery['what is a running shoe']->businessPotential(), 'A definition earns little for a shop, even with the offering named.');
        self::assertContains($byQuery['what is a running shoe']->zeroClickRisk(), ['medium', 'high']);

        $filtered = (string) $owner->get($this->url() . '?verdict=reconsider')->getBody();
        self::assertStringNotContainsString('“buy running shoes”', $filtered);
        self::assertSame(400, $owner->get($this->url() . '?verdict=maybe')->getStatusCode());
        $owner->get($this->url());

        self::assertSame(303, $owner->post($this->url() . '/keyword-rating', ['keyword' => 'Buy Running Shoes', 'score' => '0', 'back' => '?status=open'])->getStatusCode());
        self::assertSame(['buy running shoes' => 0], $profiles->ratings($this->s->acmeProject->id));
        $owner->get($this->url());
        $owner->post($this->url() . '/analyze');
        foreach ($opportunities->catalog($this->s->acmeProject->id)['rows'] as $row) {
            if ($row->keyword() === 'buy running shoes') {
                self::assertSame(0, $row->businessPotential());
                self::assertSame('reconsider', $row->verdict());
                self::assertStringContainsString('set by your team', (string) $row->evidenceValue('Business potential'));
            }
        }
        self::assertStringContainsString('Back to automatic', (string) $owner->get($this->url() . '/business')->getBody());
        self::assertSame(303, $owner->post($this->url() . '/keyword-rating', ['keyword' => 'buy running shoes', 'score' => 'auto'])->getStatusCode());
        self::assertSame([], $profiles->ratings($this->s->acmeProject->id));
        $owner->get($this->url());
        $owner->post($this->url() . '/keyword-rating', ['keyword' => 'x', 'score' => '7']);
        self::assertStringContainsString('Choose a business potential from 0 to 3', (string) $owner->get($this->url())->getBody());

        self::assertSame(303, $owner->post($this->url() . '/business/clear')->getStatusCode());
        self::assertNull($profiles->find($this->s->acmeProject->id));
    }

    public function testViewersReadAndOtherTenantsGetNotFound(): void
    {
        $viewer = $this->s->loggedIn($this->s->viewer);
        $page = (string) $viewer->get($this->url() . '/business')->getBody();
        self::assertStringContainsString('changing it needs edit access', $page);
        foreach (['/business' => ['model' => 'ecommerce', 'goal' => 'sales'], '/business/clear' => [], '/keyword-rating' => ['keyword' => 'x', 'score' => '1'], '/business/strength' => []] as $path => $data) {
            $viewer->get('/account/security');
            self::assertSame(403, $viewer->post($this->url() . $path, $data)->getStatusCode(), $path);
        }
        foreach ([$this->s->rivalOwner, $this->s->unassigned] as $user) {
            $browser = $this->s->loggedIn($user);
            self::assertSame(404, $browser->get($this->url() . '/business')->getStatusCode());
            foreach (['/business', '/business/clear', '/keyword-rating', '/business/strength'] as $path) {
                $browser->get('/account/security');
                self::assertSame(404, $browser->post($this->url() . $path, ['model' => 'ecommerce', 'goal' => 'sales', 'keyword' => 'x', 'score' => '1'])->getStatusCode(), $path);
            }
            $browser->get('/account/security');
            self::assertSame(404, $browser->post('/projects/' . $this->s->acmeProject->publicId . '/keywords/research/seed-ideas', ['location' => '2840', 'language' => 'en'])->getStatusCode());
        }
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url() . '/business');
        self::assertSame(419, $owner->post($this->url() . '/business', ['model' => 'ecommerce', 'goal' => 'sales'], withCsrf: false)->getStatusCode());
        $profiles = $this->s->container->get(InMemoryBusinessContexts::class);
        self::assertInstanceOf(InMemoryBusinessContexts::class, $profiles);
        self::assertSame([], $profiles->profiles);
    }

    public function testCompetitorStrengthChecksTheStoredResultDomainsOnRequest(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url() . '/business');
        $owner->post($this->url() . '/business/strength');
        self::assertStringContainsString('No stored results pages yet', (string) $owner->get($this->url() . '/business')->getBody());

        $serps = $this->s->container->get(InMemorySerpLandscapes::class);
        self::assertInstanceOf(InMemorySerpLandscapes::class, $serps);
        $serps->serps[$this->s->acmeProject->id]['desktop']['running shoes'] = new SerpLandscape('running shoes', '2026-10-02', [
            ['position' => 1, 'domain' => 'www.big.test', 'url' => 'https://www.big.test/c/shoes', 'title' => null],
            ['position' => 2, 'domain' => 'small.test', 'url' => 'https://small.test/shoes', 'title' => null],
        ], [], false);
        $page = (string) $owner->get($this->url() . '/business')->getBody();
        self::assertStringContainsString('2 domains', $page);
        self::assertStringContainsString('$0.024', $page);
        self::assertSame(303, $owner->post($this->url() . '/business/strength')->getStatusCode());
        $research = $this->s->container->get(InMemoryResearch::class);
        self::assertInstanceOf(InMemoryResearch::class, $research);
        self::assertSame(['big.test', 'small.test'], $research->find($this->s->acmeProject->id, 'serp_strength')?->params['domains']);

        $provider = $this->s->container->get(FakeDomainStrengthProvider::class);
        self::assertInstanceOf(FakeDomainStrengthProvider::class, $provider);
        $provider->strengths = ['big.test' => 88, 'small.test' => 9, 'elsewhere.test' => 1];
        $this->s->container->get(ResearchService::class)->run($this->s->acmeProject->id, $this->s->acme->id, 'serp_strength', '', false);
        self::assertSame(['big.test' => 88, 'small.test' => 9], $research->find($this->s->acmeProject->id, 'serp_strength')?->strengths());
        self::assertStringContainsString('2 domains rated', (string) $owner->get($this->url() . '/business')->getBody());
    }

    public function testAiSeedsAndTheSeedAndModifierLookup(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $research = '/projects/' . $this->s->acmeProject->publicId . '/keywords/research';
        $owner->get($research);
        $owner->post($research . '/seed-ideas', ['location' => '2840', 'language' => 'en']);
        self::assertStringContainsString('Describe the business and what it offers', (string) $owner->get($research)->getBody());

        $owner->get($this->url() . '/business');
        $owner->post($this->url() . '/business', ['model' => 'ecommerce', 'goal' => 'sales', 'description' => 'Running shoes online']);
        $owner->get($research);
        self::assertSame(303, $owner->post($research . '/seed-ideas', ['location' => '2840', 'language' => 'en'])->getStatusCode());
        $this->s->container->get(ResearchService::class)->run($this->s->acmeProject->id, $this->s->acme->id, 'seed_ideas', '', false);
        $suggester = $this->s->container->get(FakeSeedSuggester::class);
        self::assertInstanceOf(FakeSeedSuggester::class, $suggester);
        self::assertSame('Running shoes online', $suggester->calls[0]->description);
        $html = (string) $owner->get($research)->getBody();
        self::assertStringContainsString('words to look up, not data', $html);
        self::assertStringContainsString("running shoes\ntrail shoes</textarea>", $html, 'AI seeds prefill the lookup.');

        $provider = $this->s->container->get(FakeResearchProvider::class);
        self::assertInstanceOf(FakeResearchProvider::class, $provider);
        $provider->suggestions = [
            'running shoes' => [new ResearchKeyword('best running shoes', 9000, difficulty: 50), new ResearchKeyword('running shoes size calculator', 400, serpTypes: ['ai_overview'])],
            'trail shoes' => [new ResearchKeyword('best running shoes', 9000), new ResearchKeyword('trail shoes for beginners', 300)],
        ];
        self::assertSame(422, $owner->post($research . '/fetch', ['kind' => 'suggestions', 'location' => '2840', 'language' => 'en', 'seeds' => '', 'modifiers' => ''])->getStatusCode());
        $owner->get($research);
        self::assertSame(303, $owner->post($research . '/fetch', ['kind' => 'suggestions', 'location' => '2840', 'language' => 'en', 'seeds' => "running shoes\ntrail shoes", 'modifiers' => "best\nfor beginners"])->getStatusCode());
        $this->s->container->get(ResearchService::class)->run($this->s->acmeProject->id, $this->s->acme->id, 'suggestions', '', false);
        $store = $this->s->container->get(InMemoryResearch::class);
        self::assertInstanceOf(InMemoryResearch::class, $store);
        self::assertSame(['best running shoes', 'running shoes size calculator', 'trail shoes for beginners'], array_map(static fn(ResearchKeyword $k): string => $k->keyword, $store->find($this->s->acmeProject->id, 'suggestions')?->keywords() ?? []), 'One request per seed, duplicates kept once.');

        $modifier = (string) $owner->get($research . '?s_m=' . urlencode('*'))->getBody();
        self::assertStringContainsString('trail shoes for beginners', $modifier);
        self::assertStringNotContainsString('running shoes size calculator</td>', $modifier);
        $tools = (string) $owner->get($research . '?s_tool=1')->getBody();
        self::assertStringContainsString('running shoes size calculator', $tools);
        self::assertStringContainsString('AI Overview</span>', $tools);
    }

    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/opportunities';
    }
}
