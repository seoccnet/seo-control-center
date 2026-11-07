<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Backlinks\BacklinkProfile;
use SeoControlCenter\Domain\Backlinks\ReferringDomain;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Local\BusinessProfile;
use SeoControlCenter\Domain\Local\LocalResult;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\Rules\AiVisibilityRule;
use SeoControlCenter\Domain\Opportunities\Rules\KeywordGapRule;
use SeoControlCenter\Domain\Opportunities\Rules\LinkBuildingRule;
use SeoControlCenter\Domain\Opportunities\Rules\LocalSeoRule;
use SeoControlCenter\Domain\Opportunities\Rules\QuickWinRule;
use SeoControlCenter\Domain\Opportunities\Rules\ResearchQuickWinRule;
use SeoControlCenter\Domain\Opportunities\Rules\SiteIssueRule;
use SeoControlCenter\Domain\Rankings\RankedKeyword;
use SeoControlCenter\Domain\Research\AiMention;
use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;

/**
 * The rules on keyword research, backlinks, AI visibility and local SEO,
 * and how other sources sharpen the existing rules.
 */
final class CrossSourceRulesTest extends TestCase
{
    public function testUntrackedResearchKeywordsNearTheTop(): void
    {
        $signals = new ProjectSignals(
            queries: [new DimensionRow('blue shoes', new Metrics(5, 300, 8.0))],
            keywords: [self::ranked('red shoes', 6, 1000)],
            researchDate: '2026-10-01',
            researchRanked: [
                new ResearchKeyword('green shoes', 2000, 1.0, 20, 'commercial', 7, 'https://acme.test/green'),
                new ResearchKeyword('Red Shoes', 1000, null, 40, null, 6),  // tracked
                new ResearchKeyword('blue shoes', 900, null, 40, null, 8),  // a Search Console query: QuickWinRule's
                new ResearchKeyword('rare shoes', 20, null, 10, null, 5),   // too little volume
                new ResearchKeyword('top shoes', 5000, null, 70, null, 2),  // already top 3
                new ResearchKeyword('hard shoes', 400, null, 80, null, 15),
            ],
        );

        $found = (new ResearchQuickWinRule())->evaluate($signals);

        self::assertSame(['research:green shoes', 'research:hard shoes'], array_map(static fn(Opportunity $o): string => $o->subject, $found), 'largest potential first');
        self::assertSame(OpportunityType::QuickWin, $found[0]->type);
        self::assertSame(Opportunity::EFFORT_LOW, $found[0]->effort, 'difficulty 20');
        self::assertSame(Opportunity::EFFORT_HIGH, $found[1]->effort, 'difficulty 80');
        self::assertContains(['label' => 'Keyword difficulty', 'value' => '20 / 100'], $found[0]->evidence);
        self::assertStringContainsString('not tracked yet', $found[0]->explanation);

        $tracked = (new QuickWinRule())->evaluate(new ProjectSignals(keywords: [self::ranked('red shoes', 6, 1000)], researchRanked: [new ResearchKeyword('red shoes', 1000, null, 75, null, 6)]));
        self::assertSame(Opportunity::EFFORT_HIGH, $tracked[0]->effort, 'research difficulty sets the effort of tracked quick wins');
        self::assertContains(['label' => 'Keyword difficulty', 'value' => '75 / 100'], $tracked[0]->evidence);
    }

    public function testKeywordGapsCombineCompetitors(): void
    {
        $gap = static fn(string $competitor, string $keyword, int $volume, int $position, ?int $difficulty = null): array => ['competitor' => $competitor, 'keyword' => new ResearchKeyword($keyword, $volume, null, $difficulty, 'informational', $position, 'https://' . $competitor . '/' . str_replace(' ', '-', $keyword))];
        $found = (new KeywordGapRule())->evaluate(new ProjectSignals(
            queries: [new DimensionRow('seed guide', new Metrics(3, 200, 14.0))],
            keywords: [self::ranked('red shoes', 30, 500)],
            researchGaps: [
                $gap('rival.test', 'grow tips', 1000, 4, 25),
                $gap('other.test', 'grow tips', 1000, 2, 25),
                $gap('rival.test', 'big topic', 3000, 9),
                $gap('rival.test', 'seed guide', 5000, 3),   // the site already ranks for it
                $gap('rival.test', 'red shoes', 5000, 3),    // tracked: CompetitorGapRule's
                $gap('rival.test', 'deep topic', 5000, 15),  // competitor not on page one
                $gap('rival.test', 'tiny topic', 50, 1),     // too little volume
            ],
        ));

        self::assertSame(['kwgap:big topic', 'kwgap:grow tips'], array_map(static fn(Opportunity $o): string => $o->subject, $found));
        $grow = $found[1];
        self::assertSame('Create a page for “grow tips” (2 competitors on page one)', $grow->title);
        self::assertSame(0, $grow->confidence, 'confidence is assigned by the evidence model during analysis');
        self::assertSame(Opportunity::EFFORT_LOW, $grow->effort);
        self::assertContains(['label' => 'Competitors', 'value' => 'other.test #2, rival.test #4'], $grow->evidence);
        self::assertContains(['label' => 'Their page', 'value' => 'https://other.test/grow-tips'], $grow->evidence);
        self::assertSame(Opportunity::EFFORT_HIGH, $found[0]->effort, 'unknown difficulty: a new page is work');
    }

    public function testLinkBuildingFromProfilesProspectsAndLostLinks(): void
    {
        $signals = new ProjectSignals(
            domain: 'shop.test',
            backlinksDate: '2026-10-03',
            backlinkProfiles: [
                new BacklinkProfile('shop.test', 35, 147, 105, 30, 35),
                new BacklinkProfile('a.test', 62, 165003, 3096),
                new BacklinkProfile('b.test', 61, 544500, 5113),
                new BacklinkProfile('c.test', 40, 25692, 1234),
            ],
            linkGap: [
                new ReferringDomain('mag.test', 62, 5, 1, null, null, 3),
                new ReferringDomain('blog.test', 30, 2, 5, null, null, 2),
                new ReferringDomain('one.test', 80, 2, 5, null, null, 1),   // only one competitor
                new ReferringDomain('spam.test', 50, 2, 60, null, null, 3), // spammy
                new ReferringDomain('weak.test', 5, 2, 0, null, null, 3),   // weak
            ],
            lostLinks: [new ReferringDomain('news.test', 70, 1, 0, '2020-01-01', '2026-09-30'), new ReferringDomain('tiny.test', 3, 1, 0, null, '2026-09-29')],
            competitorCount: 3,
        );

        $found = (new LinkBuildingRule())->evaluate($signals);
        $subjects = array_map(static fn(Opportunity $o): string => $o->subject, $found);

        self::assertSame(['links:authority', 'links:spam', 'link:mag.test', 'link:blog.test', 'lost:news.test'], $subjects);
        self::assertSame('Build referring domains: you have 105, your competitors a median of 3,096', $found[0]->title);
        self::assertStringContainsString('Start with the 2 link prospects', $found[0]->explanation);
        self::assertSame('Get a link from mag.test (links to 3 of 3 competitors)', $found[2]->title);
        self::assertGreaterThan($found[3]->impact, $found[2]->impact);
        self::assertSame('Win back the link from news.test (lost 2026-09-30)', $found[4]->title);
        self::assertSame(Opportunity::EFFORT_LOW, $found[4]->effort);
        self::assertSame(OpportunityType::LinkBuilding, $found[4]->type);

        $single = (new LinkBuildingRule())->evaluate(new ProjectSignals(domain: 'shop.test', linkGap: [new ReferringDomain('one.test', 80, 2, 5, null, null, 1)], competitorCount: 1));
        self::assertSame(['link:one.test'], array_map(static fn(Opportunity $o): string => $o->subject, $single), 'with one competitor one link is enough');
    }

    public function testAiOverviewCitationsAndAssistantMentions(): void
    {
        $signals = new ProjectSignals(
            keywords: [self::ranked('grow tips', 4, 2000)],
            domain: 'shop.test',
            aiOverviewDate: '2026-10-03',
            aiOverviews: [
                ['keyword' => 'grow tips', 'position' => 4, 'cited' => false, 'domains' => ['rival.test', 'wiki.test']],
                ['keyword' => 'cited topic', 'position' => 2, 'cited' => true, 'domains' => ['shop.test']],
                ['keyword' => 'deep topic', 'position' => 35, 'cited' => false, 'domains' => ['rival.test']],
            ],
            aiMentions: [
                new AiMention('shop.test', 'chat_gpt', 4, 900),
                new AiMention('rival.test', 'chat_gpt', 120, 5000),
                new AiMention('shop.test', 'google', 30),
                new AiMention('rival.test', 'google', 40),
            ],
        );

        $found = (new AiVisibilityRule())->evaluate($signals);

        self::assertSame(['aio:grow tips', 'llm:chat_gpt'], array_map(static fn(Opportunity $o): string => $o->subject, $found), 'Google mentions are close enough');
        self::assertStringContainsString('cites rival.test, wiki.test, but not you, although you rank at position 4', $found[0]->explanation);
        self::assertSame('ChatGPT mentions rival.test 120 times, you 4', $found[1]->title);
        self::assertSame(OpportunityType::AiVisibility, $found[1]->type);
    }

    public function testLocalPackAndBusinessProfile(): void
    {
        $top = [new LocalResult(1, 'Green Valley', 'green.test', rating: 4.8, votes: 400, cid: '1'), new LocalResult(2, 'Acme Shop', 'shop.test', rating: 4.1, votes: 40, cid: '9'), new LocalResult(3, 'Garden Pro', null, rating: 4.6, votes: 200, cid: '3')];
        $signals = new ProjectSignals(
            domain: 'shop.test',
            localDate: '2026-10-03',
            businessName: 'Acme Shop',
            localRankings: [
                ['keyword' => 'seed shop', 'position' => 7, 'top' => $top],
                ['keyword' => 'grow shop', 'position' => 2, 'top' => $top],
                ['keyword' => 'plant shop', 'position' => null, 'top' => []],
            ],
            businessProfile: new BusinessProfile('Acme Shop', 'Garden center', 4.1, 40, false, 'Main St 1', null, 'https://shop.test/', 3, '9'),
        );

        $found = (new LocalSeoRule())->evaluate($signals);
        $by = [];
        foreach ($found as $o) {
            $by[$o->subject] = $o;
        }

        self::assertSame(['local:seed shop', 'local:plant shop', 'gbp:claim', 'gbp:reviews', 'gbp:complete'], array_keys($by), 'grow shop is in the local pack');
        self::assertSame('Get into the local pack for “seed shop” (now #7)', $by['local:seed shop']->title);
        self::assertContains(['label' => 'Top 3', 'value' => 'Green Valley ★4.8 (400), Garden Pro ★4.6 (200)'], $by['local:seed shop']->evidence, 'the business itself is not its own competitor');
        self::assertStringContainsString('about 300 reviews on average; you have 40', $by['local:seed shop']->explanation);
        self::assertSame('Get into Google Maps results for “plant shop” (not in the top 20)', $by['local:plant shop']->title);
        self::assertSame('Collect more Google reviews: you have 40, the local pack about 300', $by['gbp:reviews']->title);
        self::assertContains(['label' => 'Missing or weak', 'value' => 'photos (3), phone number'], $by['gbp:complete']->evidence);
        self::assertSame(OpportunityType::LocalSeo, $by['gbp:claim']->type);
    }

    public function testSiteIssuesWeighTheTrafficOfAffectedPages(): void
    {
        $issue = ['type' => 'broken_page', 'label' => 'Broken page', 'severity' => 'warning', 'advice' => 'Fix it.', 'pages' => 1, 'examples' => ['/popular'], 'effort' => 2];
        $quiet = (new SiteIssueRule())->evaluate(new ProjectSignals(siteAuditDate: '2026-10-03', siteIssues: [$issue]))[0];
        $busy = (new SiteIssueRule())->evaluate(new ProjectSignals(coverage: ['search_console' => ['status' => 'available', 'date' => '2026-10-03']], panel: new \SeoControlCenter\Domain\Opportunities\PanelSnapshot(issueTraffic: ['broken_page' => ['pages' => 1, 'clicks' => 2800]]), siteAuditDate: '2026-10-03', siteIssues: [$issue]))[0];

        self::assertSame(34, $quiet->impact);
        self::assertSame(Opportunity::impactFromClicks(3000), $busy->impact);
        self::assertContains(['label' => 'Recorded clicks across affected pages', 'value' => '3,000 / month (Search Console)'], $busy->evidence);
    }

    public function testEmptySignalsFindNothing(): void
    {
        foreach ([new ResearchQuickWinRule(), new KeywordGapRule(), new LinkBuildingRule(), new AiVisibilityRule(), new LocalSeoRule()] as $rule) {
            self::assertSame([], $rule->evaluate(new ProjectSignals()));
        }
    }

    private static function ranked(string $keyword, ?int $position, ?int $volume): RankedKeyword
    {
        return new RankedKeyword('kw-' . str_replace(' ', '-', $keyword), $keyword, KeywordLocale::default(), '2026-09-26', $position, null, false, $position, 'https://acme.test/x', [], $volume);
    }

    public function testLocalConsistencyReviewRepliesAndCategory(): void
    {
        $top = [
            new LocalResult(1, 'Green Valley', 'green.test', rating: 4.8, votes: 400, category: 'Garden center', cid: '1'),
            new LocalResult(2, 'Garden Pro', null, rating: 4.6, votes: 200, category: 'Garden center', cid: '3'),
            new LocalResult(3, 'Acme Shop', 'shop.test', rating: 4.1, votes: 40, category: 'Seed supplier', cid: '9'),
        ];
        $more = [new LocalResult(1, 'Plant Hub', null, votes: 90, category: 'Plant nursery', cid: '4'), new LocalResult(2, 'Green Valley', 'green.test', votes: 400, category: 'Garden center', cid: '1')];
        $profile = new BusinessProfile('Acme Shop', 'Seed supplier', 4.1, 40, true, 'Main St 1, 12345 Springfield', '+44 33 123 456', 'https://www.shop.test/', 20, '9');
        $nap = \SeoControlCenter\Domain\Local\NapCheck::compare($profile, 'shop.test', [
            ['url' => 'https://shop.test/', 'business' => ['types' => [], 'name' => null, 'phones' => ['033123456'], 'address' => null]],
            ['url' => 'https://shop.test/contact', 'business' => ['types' => ['Organization'], 'name' => 'ACME shop Ltd', 'phones' => [], 'address' => 'Main St 12, 12345 Springfield']],
            ['url' => 'https://shop.test/logo.png', 'business' => null],
        ]);
        self::assertSame(['website' => 'ok', 'markup' => 'missing', 'name' => 'ok', 'phone' => 'ok', 'address' => 'mismatch'], $nap->fields, 'phone matches without the country code; the house number differs');
        self::assertSame('https://shop.test/contact', $nap->pageUrl);
        self::assertSame(['markup', 'address'], $nap->problems());
        $unknown = \SeoControlCenter\Domain\Local\NapCheck::compare($profile, 'shop.test', [['url' => 'https://shop.test/', 'business' => null]]);
        self::assertFalse($unknown->isKnown(), 'pages crawled before business details were recorded');
        self::assertSame('unknown', $unknown->status('name'));
        self::assertSame('mismatch', \SeoControlCenter\Domain\Local\NapCheck::compare(new BusinessProfile('Acme Shop', url: 'https://other.test/'), 'shop.test', null)->status('website'));

        $reviews = [
            ['id' => 'a', 'author' => 'Ana', 'text' => 'Bad', 'rating' => 1.0, 'published' => '2026-09-20 10:00:00 +00:00', 'reply' => null],
            ['id' => 'b', 'author' => 'Ben', 'text' => 'Good', 'rating' => 5.0, 'published' => '2026-08-01 10:00:00 +00:00', 'reply' => 'Thanks!'],
            ['id' => 'c', 'author' => '', 'text' => 'Meh', 'rating' => 2.0, 'published' => '2026-07-01 10:00:00 +00:00', 'reply' => ' '],
            ['id' => 'd', 'author' => 'Dan', 'text' => 'Fine', 'rating' => 4.0, 'published' => '2025-01-01 10:00:00 +00:00', 'reply' => null],
        ];
        $signals = new ProjectSignals(
            domain: 'shop.test',
            siteAuditDate: '2026-10-01',
            localDate: '2026-10-03',
            businessName: 'Acme Shop',
            localRankings: [['keyword' => 'seed shop', 'position' => 3, 'top' => $top], ['keyword' => 'garden shop', 'position' => null, 'top' => $more]],
            businessProfile: $profile,
            napCheck: $nap,
            localReviews: ['items' => $reviews, 'fetched' => '2026-10-02'],
        );
        $by = [];
        foreach ((new LocalSeoRule())->evaluate($signals) as $o) {
            $by[$o->subject] = $o;
        }

        self::assertSame('Make your website match your Business Profile: LocalBusiness markup, address', $by['gbp:nap']->title);
        self::assertContains(['label' => 'Page', 'value' => 'https://shop.test/contact'], $by['gbp:nap']->evidence);
        self::assertSame('Answer 2 critical Google reviews', $by['gbp:replies']->title);
        self::assertContains(['label' => 'Owner replies', 'value' => '1 of 4'], $by['gbp:replies']->evidence, 'a blank reply is no reply');
        self::assertContains(['label' => 'Answer first', 'value' => 'Ana (1★, 2026-09-20), Reviewer (2★, 2026-07-01)'], $by['gbp:replies']->evidence);
        self::assertSame('Consider “Garden center” as your Business Profile category', $by['gbp:category']->title);
        self::assertContains(['label' => 'Local pack categories', 'value' => 'Garden center (3), Plant nursery (1)'], $by['gbp:category']->evidence, 'the business itself is not counted');

        $insights = \SeoControlCenter\Domain\Local\ReviewInsights::from($reviews, $profile, [$top[0], $top[1], ...$more], '2026-10-02');
        self::assertNotNull($insights);
        self::assertSame([4, 3.0, 2, 0.5], [$insights->count, $insights->average, $insights->critical, $insights->perMonth], '3 reviews in the 6 months before the import');
        self::assertEqualsWithDelta(230.0, $insights->leaderVotes, 0.01, 'Green Valley counts once, by its Google ID');
        self::assertTrue($insights->behindLeaders());
        self::assertNull(\SeoControlCenter\Domain\Local\ReviewInsights::from([], $profile, [], null));

        self::assertSame(['local_profile', 'site_audit'], \SeoControlCenter\Domain\Opportunities\SignalRequirements::for('local_seo', 'gbp:nap'));
        self::assertSame('local_seo:category', \SeoControlCenter\Domain\Opportunities\PlaybookLibrary::key(OpportunityType::LocalSeo, 'gbp:category'));
        $plan = (new \SeoControlCenter\Domain\Opportunities\PlaybookLibrary())->for(OpportunityType::LocalSeo, 'gbp:category', $by['gbp:category']->evidence);
        self::assertSame('Choose the Business Profile category the local pack uses', $plan->title);
        self::assertSame('guidance', (new \SeoControlCenter\Domain\Opportunities\PlaybookLibrary())->for(OpportunityType::LocalSeo, 'gbp:replies')->tested);

        $quiet = new ProjectSignals(localDate: '2026-10-03', localRankings: [['keyword' => 'seed shop', 'position' => 1, 'top' => [new LocalResult(1, 'Acme Shop', 'shop.test', category: 'Seed supplier', cid: '9'), new LocalResult(2, 'Green Valley', category: 'Garden center')]]], businessProfile: $profile, domain: 'shop.test');
        self::assertSame([], array_values(array_filter((new LocalSeoRule())->evaluate($quiet), static fn(Opportunity $o): bool => in_array($o->subject, ['gbp:nap', 'gbp:replies', 'gbp:category'], true))), 'too few leaders for a category gap; no audit or reviews to compare');
    }

    public function testBrandSearchesBelowFirstPlace(): void
    {
        $brand = ['northwindgoods'];
        foreach (['northwind good', 'Northwind Goods', 'northwindgoods seeds', 'trail northwind good', 'NORTHWINDGOOD'] as $q) {
            self::assertTrue(\SeoControlCenter\Domain\Opportunities\Rules\BrandSearchRule::isBrand($q, $brand), $q);
        }
        foreach (['prime', 'cultivar seeds', 'primer cultivars', 'best cultivars prime time'] as $q) {
            self::assertFalse(\SeoControlCenter\Domain\Opportunities\Rules\BrandSearchRule::isBrand($q, $brand), $q);
        }
        self::assertFalse(\SeoControlCenter\Domain\Opportunities\Rules\BrandSearchRule::isBrand('northwind good', []));

        $row = static fn(string $q, int $clicks, int $impressions, float $position): DimensionRow => new DimensionRow($q, new Metrics($clicks, $impressions, $position));
        $signals = new ProjectSignals(queries: [
            $row('northwind good', 0, 196, 7.1),
            $row('trail northwind good', 3, 156, 5.9),
            $row('northwindgoods', 90, 300, 1.0),
            $row('northwind goods reviews', 0, 19, 4.0),
            $row('grape roma seeds', 25, 400, 6.0),
        ], searchConsoleWindow: '2026-10-01 – 2026-10-28', brandNames: $brand);

        $brandFound = (new \SeoControlCenter\Domain\Opportunities\Rules\BrandSearchRule())->evaluate($signals);
        self::assertSame(['brand:northwind good', 'brand:trail northwind good'], array_map(static fn(Opportunity $o): string => $o->subject, $brandFound), 'first place and too few impressions are left out');
        self::assertSame(OpportunityType::QuickWin, $brandFound[0]->type);
        self::assertStringStartsWith('Rank first for your brand search “northwind good”', $brandFound[0]->title);
        self::assertContains(['label' => 'Brand words', 'value' => 'northwindgoods'], $brandFound[0]->evidence);
        // Finding 43: the scoring models read the "Query" label.
        self::assertContains(['label' => 'Query', 'value' => 'northwind good'], $brandFound[0]->evidence);
        $model = new \SeoControlCenter\Domain\Opportunities\ConfidenceModel($signals);
        $o = $brandFound[0];
        $unlabeled = new Opportunity($o->type, $o->subject, $o->title, $o->explanation, array_map(static fn(array $e): array => $e['label'] === 'Query' ? ['label' => 'Brand search', 'value' => $e['value']] : $e, $o->evidence), $o->impact, 0, $o->effort);
        self::assertGreaterThan($model->assess($unlabeled, ['search_console'])['score'], $model->assess($o, ['search_console'])['score'], 'the sample size behind the search counts');
        // Finding 44: a name made only of generic words is not a brand.
        foreach (['Web Shop', 'web-shop', 'Online Store', 'My Site', 'shop', 'Test', ''] as $generic) {
            self::assertTrue(\SeoControlCenter\Domain\Opportunities\Rules\BrandSearchRule::isGenericName($generic), $generic);
        }
        foreach (['Northwind Goods', 'Acme Shop', 'XYZ', 'ORBIT SEO'] as $brandName) {
            self::assertFalse(\SeoControlCenter\Domain\Opportunities\Rules\BrandSearchRule::isGenericName($brandName), $brandName);
        }
        self::assertSame([], (new \SeoControlCenter\Domain\Opportunities\Rules\BrandSearchRule())->evaluate(new ProjectSignals(queries: [$row('northwind good', 0, 196, 7.1)])), 'no brand words, no brand searches');

        $quick = array_map(static fn(Opportunity $o): string => $o->subject, (new QuickWinRule())->evaluate($signals));
        self::assertContains('query:grape roma seeds', $quick);
        self::assertNotContains('query:northwind good', $quick, 'brand searches are the brand rule’s');
        self::assertNotContains('query:trail northwind good', $quick);

        self::assertSame(['search_console'], \SeoControlCenter\Domain\Opportunities\SignalRequirements::for('quick_win', 'brand:northwind good'));
        $plan = (new \SeoControlCenter\Domain\Opportunities\PlaybookLibrary())->for(OpportunityType::QuickWin, 'brand:northwind good', $brandFound[0]->evidence);
        self::assertSame(['quick_win:brand', 'Rank first for your own brand', 'Be the first result when people search “northwind good”.'], [$plan->key, $plan->title, $plan->goal]);
    }
}
