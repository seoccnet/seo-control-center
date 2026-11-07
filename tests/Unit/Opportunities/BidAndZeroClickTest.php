<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Opportunities\BidModel;
use SeoControlCenter\Domain\Opportunities\BusinessContext;
use SeoControlCenter\Domain\Opportunities\BusinessPotential;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\SerpLandscape;
use SeoControlCenter\Domain\Opportunities\ValueModel;
use SeoControlCenter\Domain\Opportunities\ZeroClickModel;
use SeoControlCenter\Domain\Rankings\RankedKeyword;
use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * Business potential, the BID verdict and the zero-click risk: explicit
 * points policies on stored data, each reason visible in the evidence.
 */
final class BidAndZeroClickTest extends TestCase
{
    public function testBusinessPotentialFollowsGoalIntentAndOfferings(): void
    {
        $shop = new BusinessPotential(new BusinessContext('ecommerce', 'sales', offerings: ['running shoes'], exclusions: ['used']), ['blue running shoes' => 0], ['acme']);
        self::assertSame(3, $shop->of('buy running shoes')['score']);
        self::assertSame(3, $shop->of('best trail running shoes')['score']);
        self::assertSame('commercial', $shop->of('trail running shoes')['intent'], 'A bare product search is commercial.');
        $definition = $shop->of('what is pronation');
        self::assertSame(0, $definition['score'], 'A quick answer that mentions nothing the shop sells.');
        self::assertStringContainsString('mentions none of your offerings', implode(' ', $definition['reasons']));
        self::assertSame(0, $shop->of('used running shoes')['score'], 'Excluded term.');
        self::assertSame(0, $shop->of('shoe store jobs')['score'], 'Job searches never buy.');
        self::assertSame(['score' => 2, 'source' => 'rules', 'intent' => 'navigational', 'reasons' => ['mentions your own brand “acme”: protect it, but searchers already know you']], $shop->of('acme shoes'));
        self::assertSame('your rating', $shop->of('Blue  Running Shoes')['source'], 'A team rating wins over every rule.');
        self::assertSame(0, $shop->of('blue running shoes')['score']);

        $publisher = new BusinessPotential(new BusinessContext('publisher', 'traffic'));
        self::assertSame(3, $publisher->of('how to choose running shoes step by step')['score']);
        self::assertStringContainsString('relevance unchecked', implode(' ', $publisher->of('marathon plan')['reasons']));

        $plumber = new BusinessPotential(new BusinessContext('local', 'sales'));
        self::assertSame(3, $plumber->of('plumber near me')['score'], 'A local business wins customers from local searches.');
    }

    public function testBusinessContextValidatesInput(): void
    {
        $context = BusinessContext::fromInput(['model' => 'saas', 'goal' => 'signups', 'offerings' => "Project Software\nproject software, Kanban ;", 'max_difficulty' => '45', 'max_strength' => '']);
        self::assertSame(['project software', 'kanban'], $context->offerings);
        self::assertSame(45, $context->difficultyLimit());
        self::assertSame(BusinessContext::DEFAULT_MAX_STRENGTH, $context->strengthLimit());
        try {
            BusinessContext::fromInput(['model' => 'castle', 'goal' => 'sales', 'max_difficulty' => '101']);
            self::fail('Invalid profile accepted.');
        } catch (ValidationException $e) {
            self::assertSame(['model', 'max_difficulty'], array_keys($e->errors()));
        }
    }

    public function testBidTargetsAValuableWinnableSearchWithTheRightFormat(): void
    {
        $signals = $this->signals(position: 8, url: 'https://acme.test/collections/running-shoes', difficulty: 22, strengths: ['big.test' => 85, 'weak.test' => 14]);
        $result = (new BidModel($signals))->assess($this->finding('running shoes'));
        self::assertNotNull($result);
        self::assertSame('Target', $result['verdict']);
        self::assertSame(['score' => 3, 'source' => 'rules', 'weight' => 1.2, 'label' => '3/3 (rules) ×1.20'], $result['business']);
        $evidence = array_column($result['evidence'], 'value', 'label');
        self::assertStringStartsWith('3/3: your page is a category or listing page, like most results', $evidence['Format fit']);
        self::assertStringContainsString('difficulty 22 within your limit 30 (+1)', $evidence['Rankability']);
        self::assertStringContainsString('weakest result weak.test (#3) has strength 14, within your limit 20 (+1)', $evidence['Rankability']);
        self::assertStringContainsString('you already rank at position 8 (+1)', $evidence['Rankability']);
        self::assertStringStartsWith('category or listing page: a category page', $evidence['Recommended format']);
        self::assertStringStartsWith('Target: valuable, winnable and the format fits. B 3/3 · I 3/3 · D 3/3', $evidence['BID verdict']);
    }

    public function testBidFlagsAFormatMismatchAndReconsidersWhatIsTooHard(): void
    {
        $mismatch = (new BidModel($this->signals(position: 8, url: 'https://acme.test/blog/running-shoes-guide', difficulty: 22)))->assess($this->finding('running shoes'));
        self::assertSame('Consider', $mismatch['verdict'] ?? null);
        self::assertStringStartsWith('1/3: Google favours category or listing pages here', array_column($mismatch['evidence'] ?? [], 'value', 'label')['Format fit']);

        $hard = (new BidModel($this->signals(position: null, url: null, difficulty: 70, strengths: ['big.test' => 85, 'weak.test' => 60, 'shop.test' => 70, 'other.test' => 75])))->assess($this->finding('running shoes'));
        self::assertSame('Reconsider', $hard['verdict'] ?? null);
        self::assertStringContainsString('harder than your difficulty limit', array_column($hard['evidence'] ?? [], 'value', 'label')['BID verdict']);

        $worthless = (new BidModel($this->signals(position: 5, url: null, difficulty: 10)))->assess($this->finding('running shoes jobs'));
        self::assertSame('Reconsider', $worthless['verdict'] ?? null);
    }

    public function testWithoutProfileBidExplainsWhatIsMissingAndGivesNoVerdict(): void
    {
        $result = (new BidModel(new ProjectSignals()))->assess($this->finding('running shoes'));
        self::assertNotNull($result);
        self::assertNull($result['verdict']);
        self::assertNull($result['business']);
        $evidence = array_column($result['evidence'] ?? [], 'value', 'label');
        self::assertStringStartsWith('Unknown: describe the business', $evidence['Business potential']);
        self::assertStringStartsWith('Unknown: no stored results page', $evidence['Format fit']);
        self::assertStringContainsString('difficulty unknown (+0)', $evidence['Rankability']);
        self::assertNull((new BidModel(new ProjectSignals()))->assess(new Opportunity(OpportunityType::SiteIssue, 'site:x', '', '', [['label' => 'Page', 'value' => '/x']], 10, 0, 1)), 'Page findings have no BID.');
    }

    public function testBusinessWeightReplacesTheGenericIntentWeight(): void
    {
        $finding = new Opportunity(OpportunityType::QuickWin, 'query:what is pronation', '', '', [['label' => 'Query', 'value' => 'what is pronation']], 50, 0, 1, estimatedClicks: 100.0);
        $plain = (new ValueModel(new ProjectSignals()))->assess($finding);
        $weighted = (new ValueModel(new ProjectSignals()))->assess($finding, ['score' => 0, 'source' => 'rules', 'weight' => 0.4, 'label' => '0/3 (rules) ×0.40']);
        self::assertSame(Opportunity::impactFromClicks(100 * 0.9), $plain['impact']);
        self::assertSame(Opportunity::impactFromClicks(100 * 0.4), $weighted['impact']);
        self::assertContains('Business weight', array_column($weighted['evidence'], 'label'));
        self::assertNotContains('Intent', array_column($weighted['evidence'], 'label'));
    }

    public function testZeroClickRiskFromOverviewsFeaturesWordingAndCtr(): void
    {
        $signals = new ProjectSignals(
            queries: [new DimensionRow('what is seo', new Metrics(2, 4000, 2.0)), new DimensionRow('best seo tools', new Metrics(200, 2000, 2.0))],
            keywords: [new RankedKeyword('kw-1', 'what is seo', KeywordLocale::default(), '2026-10-09', 2, null, false, 2, null, [], 1000)],
            aiOverviewDate: '2026-10-09',
            aiOverviews: [['keyword' => 'what is seo', 'position' => 2, 'cited' => false, 'domains' => []], ['keyword' => 'best seo tools', 'position' => 2, 'cited' => false, 'domains' => []]],
            serps: ['what is seo' => new SerpLandscape('what is seo', '2026-10-09', [], ['featured_snippet', 'people_also_ask'], true)],
        );
        $model = new ZeroClickModel($signals);
        $high = $model->assess($this->finding('What is SEO'));
        self::assertSame('high', $high['level'] ?? null);
        self::assertSame(6, $high['score'] ?? null, '+2 overview, +1 snippet, +2 definition, +1 CTR far below expected');
        self::assertSame(-10, $high['adjustment'] ?? null);
        self::assertSame(['serp_landscape', 'ai_overviews'], $high['sources'] ?? null);
        self::assertStringStartsWith('High (estimate): +2 Google showed an AI Overview on the latest rank check (2026-10-09)', $high['evidence'][0]['value'] ?? '');
        self::assertStringContainsString('the click estimate is not reduced', $high['evidence'][0]['value'] ?? '');

        $comparison = $model->assess($this->finding('best seo tools'));
        self::assertSame('low', $comparison['level'] ?? null, 'An overview does not settle a comparison: +2 −2.');
        self::assertSame(0, $comparison['adjustment'] ?? null);

        $unknown = $model->assess($this->finding('how to tie shoes'));
        self::assertSame('medium', $unknown['level'] ?? null);
        self::assertStringContainsString('No results-page data for this query', $unknown['evidence'][0]['value'] ?? '');

        $research = new ZeroClickModel(new ProjectSignals(researchIdeas: [new ResearchKeyword('meaning of seo', 10, serpTypes: ['ai_overview', 'knowledge_graph'])]));
        self::assertSame(['research_ideas'], $research->assess($this->finding('meaning of seo'))['sources'] ?? null);
        self::assertSame(5, $research->score('meaning of seo')['score']);
        self::assertNull($model->assess(new Opportunity(OpportunityType::SiteIssue, 'site:x', '', '', [], 10, 0, 1)));
    }

    private function finding(string $query): Opportunity
    {
        return new Opportunity(OpportunityType::QuickWin, 'query:' . $query, '', '', [['label' => 'Query', 'value' => $query]], 40, 0, 1, estimatedClicks: 50.0);
    }

    /** @param array<string, int> $strengths */
    private function signals(?int $position, ?string $url, ?int $difficulty, array $strengths = []): ProjectSignals
    {
        $results = [];
        foreach (['https://big.test/collections/running', 'https://shop.test/category/running', 'https://weak.test/shop/running', 'https://other.test/collections/run', 'https://mag.test/blog/shoes'] as $i => $u) {
            $results[] = ['position' => $i + 1, 'domain' => (string) parse_url($u, PHP_URL_HOST), 'url' => $u, 'title' => null];
        }
        if ($url !== null && $position !== null) {
            $results[] = ['position' => $position, 'domain' => 'acme.test', 'url' => $url, 'title' => null];
        }

        return new ProjectSignals(
            keywords: $position !== null ? [new RankedKeyword('kw-1', 'running shoes', KeywordLocale::default(), '2026-10-09', $position, null, false, $position, $url, [], 5000)] : [],
            domain: 'acme.test',
            researchRanked: $difficulty !== null ? [new ResearchKeyword('running shoes', 5000, difficulty: $difficulty, position: $position)] : [],
            business: new BusinessContext('ecommerce', 'sales', offerings: ['running shoes']),
            serps: ['running shoes' => new SerpLandscape('running shoes', '2026-10-09', $results, [], false)],
            domainStrengths: $strengths,
        );
    }
}
