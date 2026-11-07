<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Opportunities\BusinessContext;
use SeoControlCenter\Domain\Opportunities\KeywordClusterer;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\Rules\ClickErosionRule;
use SeoControlCenter\Domain\Opportunities\Rules\FormatMismatchRule;
use SeoControlCenter\Domain\Opportunities\Rules\PromptGapRule;
use SeoControlCenter\Domain\Opportunities\Rules\ToolIdeaRule;
use SeoControlCenter\Domain\Opportunities\SerpLandscape;
use SeoControlCenter\Domain\Opportunities\TrendSnapshot;
use SeoControlCenter\Domain\Rankings\RankedKeyword;
use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Domain\Research\SeedIdeas;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;

final class IntelligenceRulesTest extends TestCase
{
    private const string WINDOW = '2026-09-12 – 2026-10-09';

    public function testClicksFallingWhileRankingAndDemandHold(): void
    {
        $now = [
            self::row('red shoes', 50, 1000, 3.2),      // CTR halved, position held: found
            self::row('blue shoes', 50, 1000, 6.0),     // lost three places: a ranking problem
            self::row('green shoes', 25, 500, 3.0),     // demand halved
            self::row('tiny shoes', 4, 300, 3.0),       // too few clicks before
            self::row('noisy shoes', 18, 260, 3.0),     // small drop, not significant
        ];
        $before = [
            'red shoes' => new Metrics(100, 1000, 3.0),
            'blue shoes' => new Metrics(100, 1000, 3.0),
            'green shoes' => new Metrics(100, 1000, 3.0),
            'tiny shoes' => new Metrics(10, 300, 3.0),
            'noisy shoes' => new Metrics(25, 250, 3.0),
        ];
        $signals = new ProjectSignals(
            self::WINDOW,
            $now,
            keywords: [new RankedKeyword('kw-1', 'red shoes', KeywordLocale::default(), '2026-10-09', 3, null, false, 3, null, [], 1000)],
            aiOverviewDate: '2026-10-09',
            aiOverviews: [['keyword' => 'red shoes', 'position' => 3, 'cited' => false, 'domains' => ['rival.test']]],
            trends: new TrendSnapshot($now, previousQueries: $before),
            serps: ['red shoes' => new SerpLandscape('red shoes', '2026-10-09', [], ['ai_overview', 'people_also_ask'], true)],
            aiOverviewsEarlier: [],
            aiOverviewEarlierDate: '2026-09-10',
        );

        $found = (new ClickErosionRule())->evaluate($signals);

        self::assertSame(['query:red shoes'], array_map(static fn(Opportunity $o): string => $o->subject, $found));
        $red = $found[0];
        self::assertSame(OpportunityType::ClickErosion, $red->type);
        self::assertSame('“red shoes” lost 50.0 % of its clicks while holding position 3.2', $red->title);
        $evidence = array_column($red->evidence, 'value', 'label');
        self::assertSame('10.0 % → 5.0 %', $evidence['CTR']);
        self::assertStringStartsWith('Appeared between the checks of 2026-09-10 and 2026-10-09', $evidence['AI Overview']);
        self::assertStringContainsString('correlation, not proof', $evidence['AI Overview']);
        self::assertSame('ai overview, people also ask (rank check of 2026-10-09)', $evidence['Results page features']);
        self::assertEqualsWithDelta(50 * 30 / 28, $red->estimatedClicks, 0.01);
        self::assertGreaterThan(1.96, ClickErosionRule::ctrDropZ(100, 1000, 50, 1000));
        self::assertLessThan(1.96, ClickErosionRule::ctrDropZ(25, 250, 18, 260));
        self::assertSame([], (new ClickErosionRule())->evaluate(new ProjectSignals(self::WINDOW, $now)), 'Without the preceding window nothing is compared.');
    }

    public function testFormatMismatchNeedsADominantFormatAndAClassifiedOwnPage(): void
    {
        $categories = self::serp(['https://a.test/collections/x', 'https://b.test/category/x', 'https://c.test/shop/x', 'https://d.test/collections/y', 'https://e.test/blog/z']);
        $forums = self::serp(['https://www.reddit.com/r/a', 'https://www.quora.com/b', 'https://www.reddit.com/r/c', 'https://e.test/blog/z']);
        $signals = new ProjectSignals(
            keywords: [
                self::ranked('kw-1', 'running shoes', 7, 5000, 'https://acme.test/blog/best-running-shoes-guide'),
                self::ranked('kw-2', 'trail shoes', 7, 900, 'https://acme.test/collections/trail'),     // right format
                self::ranked('kw-3', 'shoe advice', 6, 300, 'https://acme.test/blog/advice'),          // forums lead: not a page to rebuild
                self::ranked('kw-4', 'cheap shoes', 2, 800, 'https://acme.test/blog/cheap'),           // already top 3
            ],
            domain: 'acme.test',
            serps: ['running shoes' => $categories, 'trail shoes' => $categories, 'shoe advice' => $forums, 'cheap shoes' => $categories],
        );

        $found = (new FormatMismatchRule())->evaluate($signals);

        self::assertCount(1, $found);
        self::assertSame('format:kw-1', $found[0]->subject);
        self::assertSame('Google ranks category or listing pages for “running shoes”; your page is a review or comparison page', $found[0]->title);
        self::assertSame(Opportunity::EFFORT_HIGH, $found[0]->effort, 'Building a category page is structural work.');
        self::assertStringContainsString('4 × category or listing page', array_column($found[0]->evidence, 'value', 'label')['Top results']);
    }

    public function testToolIdeasGroupVariantsAndSkipProductsAndExclusions(): void
    {
        $signals = new ProjectSignals(
            queries: [self::row('loan calculator', 4, 600, 14.0), self::row('bmi calculator', 90, 900, 2.0)],
            researchIdeas: [
                new ResearchKeyword('mortgage calculator', 5000, difficulty: 40),
                new ResearchKeyword('free mortgage calculator online', 800, difficulty: 25),
                new ResearchKeyword('mortgage calculator uk', 300),
                new ResearchKeyword('coffee maker', 9000),
                new ResearchKeyword('kitchen counter', 4000),
                new ResearchKeyword('salary calculator jobs', 700),
                new ResearchKeyword('rent checker', 10),
            ],
            searchSources: ['search_console'],
            business: new BusinessContext('publisher', 'traffic'),
        );

        $found = (new ToolIdeaRule())->evaluate($signals);
        $subjects = array_map(static fn(Opportunity $o): string => $o->subject, $found);

        self::assertSame(['tool:calculator mortgage', 'tool:calculator loan'], $subjects, 'bmi ranks top 3, products and job searches are no tools, rent checker lacks demand');
        $mortgage = $found[0];
        $evidence = array_column($mortgage->evidence, 'value', 'label');
        self::assertSame('Build a free calculator: “mortgage calculator”', $mortgage->title);
        self::assertSame('6,100 searches or impressions per month across 3 variants', $evidence['Total demand']);
        self::assertSame('40 / 100 (hardest variant)', $evidence['Keyword difficulty']);
        self::assertStringStartsWith('Moderate: a few inputs and a formula', $evidence['Feasibility']);
        self::assertSame(Opportunity::EFFORT_MEDIUM, $mortgage->effort);
        $rule = new ToolIdeaRule();
        $rule->evaluate($signals);
        self::assertSame(['research_ideas'], $rule->requires($found[0]));
        self::assertSame(['search_console'], $rule->requires($found[1]));
        self::assertSame('calculator mortgage', ToolIdeaRule::concept('Free Online Mortgage Calculators'));
    }

    public function testPromptGapNeedsTwoPlatformsAgreeingAndTheBrandAbsent(): void
    {
        $rival = ['domain' => 'rival.test', 'mentioned' => true, 'cited' => false];
        $cited = ['domain' => 'rival.test', 'mentioned' => false, 'cited' => true];
        $signals = new ProjectSignals(
            keywords: [self::ranked('kw-1', 'project management software', 12, 3000, null)],
            aiAnswers: [
                self::answer('Best project management software?', 'gemini', false, [$rival]),
                self::answer('Best project management software?', 'perplexity', false, [$cited]),
                self::answer('Cheapest kanban tool?', 'gemini', true, [$rival]),
                self::answer('Cheapest kanban tool?', 'perplexity', false, [$rival]),
                self::answer('Gantt tool for agencies?', 'gemini', false, [$rival]),
                self::answer('Gantt tool for agencies?', 'perplexity', false, []),
                self::answer('Only one answer?', 'gemini', false, [$rival]),
            ],
            aiAnswersDate: '2026-10-08',
        );

        $found = (new PromptGapRule())->evaluate($signals);

        self::assertCount(1, $found);
        self::assertSame('prompt:best project management software?', $found[0]->subject);
        self::assertSame(OpportunityType::AiVisibility, $found[0]->type);
        self::assertNull($found[0]->estimatedClicks, 'A sample has no click estimate.');
        $evidence = array_column($found[0]->evidence, 'value', 'label');
        self::assertSame('rival.test: Gemini named, Perplexity cited', $evidence['Recommended instead']);
        self::assertSame('project management software', $evidence['Related keywords']);
        self::assertStringContainsString('not how often you are mentioned', $evidence['Sample']);
    }

    public function testClustersFollowSharedResultsThenSharedWordsWithoutChaining(): void
    {
        $topics = (new KeywordClusterer())->cluster([
            'running shoes' => 5000.0, 'best running shoes' => 2000.0, 'running shoes for women' => 900.0,
            'shoes' => 9000.0, 'red shoes' => 800.0, 'shoe repair' => 300.0,
            'trail running' => 400.0, 'trail running tips' => 50.0,
        ]);
        self::assertSame(['label' => 'running shoes', 'size' => 3], $topics['best running shoes']);
        self::assertSame(['label' => 'running shoes', 'size' => 3], $topics['running shoes for women']);
        self::assertSame(1, $topics['shoes']['size'], 'A single word never bridges topics.');
        self::assertSame(1, $topics['red shoes']['size']);
        self::assertSame('trail running', $topics['trail running tips']['label']);

        $intents = (new KeywordClusterer())->cluster(['running shoes' => 5000.0, 'best running shoes' => 2000.0, 'running shoe size calculator' => 400.0, 'how to clean running shoes' => 300.0]);
        self::assertSame(2, $intents['best running shoes']['size'], 'The neutral head term joins one intent only.');
        self::assertSame(1, $intents['running shoe size calculator']['size'], 'A tool search is its own topic.');
        self::assertSame(1, $intents['how to clean running shoes']['size'], 'No chaining across intents through the head term.');

        /** @return list<string> */
        $urls = static fn(string ...$paths): array => array_values(array_map(static fn(string $p): string => 'site.test/' . $p, $paths));
        $serp = (new KeywordClusterer())->cluster(['seo audit' => 1000.0, 'website audit' => 800.0, 'seo audit tool' => 500.0], [
            'seo audit' => $urls('a', 'b', 'c', 'd', 'e'),
            'website audit' => $urls('a', 'b', 'c', 'x', 'y'),
            'seo audit tool' => $urls('p', 'q', 'r', 's', 't'),
        ]);
        self::assertSame('seo audit', $serp['website audit']['label'], 'Three shared results join different wording.');
        self::assertSame(1, $serp['seo audit tool']['size'], 'No shared results split similar wording.');
    }

    public function testSeedIdeasAreCleanedIntoSeedsAndSeparateModifiers(): void
    {
        $ideas = SeedIdeas::clean([
            'seeds' => ['Running Shoes', 'best trail shoes', 'marathon training plan for beginners', 'best', 42, 'Socks!'],
            'modifiers' => ['best', 'For Beginners', 'how to', 'socks', 'a very long modifier phrase here'],
            'notes' => '<b>Product</b> and   comparison searches.',
        ]);
        self::assertSame(['running shoes', 'trail shoes', 'socks'], $ideas->seeds, 'Three-word seeds dropped, modifiers moved out of seeds.');
        self::assertSame(['best', 'for beginners', 'how to'], $ideas->modifiers, 'A modifier equal to a seed is dropped; the seed stays.');
        self::assertSame('Product and comparison searches.', $ideas->notes);
        self::assertSame([], SeedIdeas::clean(['seeds' => 'not a list'])->seeds);
    }

    /**
     * @param list<array{domain: string, mentioned: bool, cited: bool}> $rivals
     * @return array{prompt: string, platform: string, brand_mentioned: bool, own_cited: bool, competitors: list<array{domain: string, mentioned: bool, cited: bool}>}
     */
    private static function answer(string $prompt, string $platform, bool $brand, array $rivals): array
    {
        return ['prompt' => $prompt, 'platform' => $platform, 'brand_mentioned' => $brand, 'own_cited' => false, 'competitors' => $rivals];
    }

    private static function row(string $key, int $clicks, int $impressions, float $position): DimensionRow
    {
        return new DimensionRow($key, new Metrics($clicks, $impressions, $position));
    }

    private static function ranked(string $id, string $keyword, ?int $position, ?int $volume, ?string $url): RankedKeyword
    {
        return new RankedKeyword($id, $keyword, KeywordLocale::default(), '2026-10-09', $position, null, false, $position, $url, [], $volume);
    }

    /** @param list<string> $urls */
    private static function serp(array $urls): SerpLandscape
    {
        $results = [];
        foreach ($urls as $i => $url) {
            $results[] = ['position' => $i + 1, 'domain' => (string) parse_url($url, PHP_URL_HOST), 'url' => $url, 'title' => null];
        }

        return new SerpLandscape('kw', '2026-10-09', $results, [], false);
    }
}
