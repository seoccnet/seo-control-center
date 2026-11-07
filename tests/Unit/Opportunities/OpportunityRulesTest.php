<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Competitors\KeywordComparison;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\Rules\CompetitorGapRule;
use SeoControlCenter\Domain\Opportunities\Rules\ContentGapRule;
use SeoControlCenter\Domain\Opportunities\Rules\Format;
use SeoControlCenter\Domain\Opportunities\Rules\LowCtrRule;
use SeoControlCenter\Domain\Opportunities\Rules\QuickWinRule;
use SeoControlCenter\Domain\Opportunities\Rules\RankingDeclineRule;
use SeoControlCenter\Domain\Opportunities\Rules\SiteIssueRule;
use SeoControlCenter\Domain\Opportunities\Rules\TrafficDeclineRule;
use SeoControlCenter\Domain\Rankings\RankedKeyword;
use SeoControlCenter\Domain\Rankings\RankingMove;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;

/**
 * Each rule on hand-made signals: what it detects, what it ignores, and
 * that the evidence explains the result.
 */
final class OpportunityRulesTest extends TestCase
{
    private const string WINDOW = '2026-08-28 – 2026-09-24';

    public function testScoring(): void
    {
        self::assertSame(0, Opportunity::impactFromClicks(0));
        self::assertSame(26, Opportunity::impactFromClicks(10));
        self::assertSame(50, Opportunity::impactFromClicks(99));
        self::assertSame(100, Opportunity::impactFromClicks(1_000_000));
        self::assertSame(40.0, Opportunity::priorityOf(80, 50, 1));
        self::assertSame(13.33, Opportunity::priorityOf(80, 50, 3));
    }

    public function testQuickWinsFromTrackedKeywordsAndUntrackedQueries(): void
    {
        $signals = new ProjectSignals(
            self::WINDOW,
            [
                self::row('red shoes', 60, 900, 6.0),        // tracked: reported once, from rankings (healthy CTR)
                self::row('Green  Shoes', 3, 400, 12.4),     // untracked, page two
                self::row('rare shoes', 1, 60, 7.0),         // too few impressions
                self::row('top shoes', 50, 800, 2.0),        // already top 3
            ],
            keywords: [
                self::ranked('kw-red', 'red shoes', 6, 1000),
                self::ranked('kw-blue', 'blue shoes', 25, 5000), // beyond 20
                self::ranked('kw-none', 'no volume', 9, null),
            ],
        );

        $found = (new QuickWinRule())->evaluate($signals);

        self::assertSame(['keyword:kw-red', 'keyword:kw-none', 'query:Green  Shoes'], array_map(static fn(Opportunity $o): string => $o->subject, $found));
        [$red, $noVolume, $green] = $found;
        self::assertSame(OpportunityType::QuickWin, $red->type);
        self::assertSame('Move “red shoes” from position 6 into the top 3', $red->title);
        self::assertSame(0, $red->confidence);
        self::assertSame(Opportunity::EFFORT_LOW, $red->effort);
        self::assertSame(Opportunity::impactFromClicks(1000 * (0.11 - 0.05)), $red->impact);
        self::assertContains(['label' => 'Est. extra clicks at position 3', 'value' => '60 / month'], $red->evidence);
        self::assertSame(0, $noVolume->impact);
        self::assertStringContainsString('search volume is unknown', $noVolume->explanation);
        self::assertSame(Opportunity::EFFORT_MEDIUM, $green->effort, 'Page two needs more work.');
        self::assertSame(0, $green->confidence);
    }

    public function testLowCtrOnPageOneOnly(): void
    {
        $signals = new ProjectSignals(
            self::WINDOW,
            [
                self::row('weak snippet', 4, 1000, 2.0),     // 0.4 % at position 2 (expected 15 %)
                self::row('good snippet', 120, 1000, 2.0),   // 12 %: fine
                self::row('deep query', 0, 5000, 14.0),      // not on page one
                self::row('small query', 0, 150, 1.0),       // too few impressions
            ],
            [self::row('https://acme.test/red', 2, 900, 3.0)],
        );

        $found = (new LowCtrRule())->evaluate($signals);

        self::assertSame(['query:weak snippet', 'page:https://acme.test/red'], array_map(static fn(Opportunity $o): string => $o->subject, $found));
        self::assertSame('Improve the search snippet of /red', $found[1]->title);
        self::assertStringContainsString('about 15.0 % of searchers usually click; this query gets 0.4 %', $found[0]->explanation);
        self::assertSame(Opportunity::EFFORT_LOW, $found[0]->effort);
    }

    public function testRankingDeclinesUseVolumeAndIgnoreSmallOrDeepMoves(): void
    {
        $signals = new ProjectSignals(
            rankingDate: '2026-09-26',
            compareDate: '2026-09-19',
            keywords: [self::ranked('kw-red', 'red shoes', 11, 2000)],
            moves: [
                new RankingMove('red shoes', 4, 11),     // -7: reported
                new RankingMove('blue shoes', 8, null),  // out of the results: reported
                new RankingMove('pink shoes', 6, 9),     // -3: too small
                new RankingMove('deep shoes', 30, 60),   // was not in the top 20
                new RankingMove('up shoes', 9, 2),       // improvement
            ],
        );

        $found = (new RankingDeclineRule())->evaluate($signals);

        self::assertSame(['keyword:red shoes', 'keyword:blue shoes'], array_map(static fn(Opportunity $o): string => $o->subject, $found));
        self::assertSame('“red shoes” dropped from position 4 to 11', $found[0]->title);
        self::assertSame('“blue shoes” dropped from position 8 to outside the top 100', $found[1]->title);
        self::assertContains(['label' => 'Est. clicks lost', 'value' => '140 / month'], $found[0]->evidence, '2000 × (8 % − 1 %).');
        self::assertContains(['label' => 'Position', 'value' => '4 on 2026-09-19 → 11 on 2026-09-26'], $found[0]->evidence);
    }

    public function testTrafficDeclineComparesWindows(): void
    {
        $signals = new ProjectSignals(
            self::WINDOW,
            pages: [
                self::row('https://acme.test/a', 30, 3000, 5.0),   // 100 → 30: -70 %
                self::row('https://acme.test/b', 80, 3000, 5.0),   // 100 → 80: -20 %
                self::row('https://acme.test/c', 1, 100, 5.0),     // 15 → 1, but too few before
                self::row('https://acme.test/new', 5, 100, 5.0),   // no previous data
            ],
            previousPages: [
                'https://acme.test/a' => new Metrics(100, 4000, 4.0),
                'https://acme.test/b' => new Metrics(100, 3000, 5.0),
                'https://acme.test/c' => new Metrics(15, 300, 5.0),
            ],
        );

        $found = (new TrafficDeclineRule())->evaluate($signals);

        self::assertCount(1, $found);
        self::assertSame('Traffic to /a fell by 70.0 %', $found[0]->title);
        self::assertContains(['label' => 'Clicks', 'value' => '100 → 30 (' . self::WINDOW . ' vs the 28 days before)'], $found[0]->evidence);
    }

    public function testCompetitorGapsAndContentGaps(): void
    {
        $signals = new ProjectSignals(
            self::WINDOW,
            [self::row('deep topic', 0, 400, 34.0), self::row('rare topic', 0, 50, 40.0), self::row('close topic', 2, 400, 12.0)],
            gaps: [
                ['competitor' => 'rival.test', 'comparison' => new KeywordComparison('kw-1', 'red shoes', 1000, null, 2, 'https://rival.test/red')],
                ['competitor' => 'rival.test', 'comparison' => new KeywordComparison('kw-2', 'blue shoes', null, 25, 3, null)],
            ],
        );

        $gaps = (new CompetitorGapRule())->evaluate($signals);
        self::assertSame(['gap:rival.test:kw-1', 'gap:rival.test:kw-2'], array_map(static fn(Opportunity $o): string => $o->subject, $gaps));
        self::assertSame('rival.test ranks #2 for “red shoes”, you rank outside the top 100', $gaps[0]->title);
        self::assertSame(Opportunity::EFFORT_HIGH, $gaps[0]->effort, 'No ranking page yet.');
        self::assertSame(Opportunity::EFFORT_MEDIUM, $gaps[1]->effort);
        self::assertSame(Opportunity::impactFromClicks(150), $gaps[0]->impact);

        $content = (new ContentGapRule())->evaluate($signals);
        self::assertSame(['query:deep topic'], array_map(static fn(Opportunity $o): string => $o->subject, $content));
        self::assertSame(Opportunity::EFFORT_HIGH, $content[0]->effort);
    }

    public function testQueryWithSnippetProblemIsReportedOnceAsLowCtr(): void
    {
        // Position 8 with 956 impressions and no clicks: a quick win by position, but the snippet is the problem.
        $signals = new ProjectSignals(
            self::WINDOW,
            [self::row('medical mass', 0, 956, 8.1), self::row('fine mass', 40, 956, 8.1)],
            keywords: [self::ranked('kw-mm', 'medical mass', 8, 900)],
        );

        $quickWins = array_map(static fn(Opportunity $o): string => $o->subject, (new QuickWinRule())->evaluate($signals));
        $lowCtr = array_map(static fn(Opportunity $o): string => $o->subject, (new LowCtrRule())->evaluate($signals));

        self::assertSame(['query:fine mass'], $quickWins, 'Neither the tracked keyword nor the query is a quick win.');
        self::assertSame(['query:medical mass'], $lowCtr);
    }

    public function testPositionFormatting(): void
    {
        self::assertSame('10', Format::position(9.98));
        self::assertSame('8.1', Format::position(8.14));
        self::assertSame('3', Format::position(3.0));
        self::assertSame('not in the top 100', Format::position(null));
    }

    public function testSiteIssuesBecomeOpportunities(): void
    {
        $issue = static fn(string $type, string $severity, int $pages, string ...$examples): array => [
            'type' => $type, 'label' => ucfirst(str_replace('_', ' ', $type)), 'severity' => $severity, 'advice' => 'Fix it.', 'pages' => $pages, 'examples' => array_values($examples), 'effort' => 1,
        ];
        $found = (new SiteIssueRule())->evaluate(new ProjectSignals(siteAuditDate: '2026-10-03', siteIssues: [
            $issue('broken_page', 'error', 3, '/a', '/b'),
            $issue('missing_description', 'warning', 1),
            $issue('title_too_long', 'notice', 40),
            $issue('slow_page', 'warning', 0),
        ]));

        self::assertCount(2, $found, 'notices and empty types are left out');
        self::assertSame(OpportunityType::SiteIssue, $found[0]->type);
        self::assertSame('site:broken_page', $found[0]->subject);
        self::assertSame('Broken page on 3 pages', $found[0]->title);
        self::assertSame('Missing description on 1 page', $found[1]->title);
        self::assertSame(62, $found[0]->impact, 'errors weigh more');
        self::assertSame(34, $found[1]->impact);
        self::assertSame('/a, /b', array_column($found[0]->evidence, 'value', 'label')['For example']);
        self::assertSame('broken_page', array_column($found[0]->evidence, 'value', 'label')['Issue type']);
        self::assertSame(['label' => 'Site audit', 'value' => '2026-10-03'], $found[1]->evidence[2]);
    }

    public function testGrowthNoticesBecomeLowerImpactOpportunitiesWithTheirOwnPlans(): void
    {
        $issue = static fn(string $type, int $pages): array => ['type' => $type, 'label' => ucfirst(str_replace('_', ' ', $type)), 'severity' => 'notice', 'advice' => 'Fix it.', 'pages' => $pages, 'examples' => ['/a'], 'effort' => 1];
        $found = (new SiteIssueRule())->evaluate(new ProjectSignals(siteAuditDate: '2026-10-03', siteIssues: [
            $issue('missing_structured_data', 40),
            $issue('missing_author', 2),
            $issue('deep_page', 5),
            $issue('title_too_long', 40),
        ]));
        self::assertSame(['site:missing_structured_data', 'site:missing_author', 'site:deep_page'], array_column(array_map(static fn($o): array => ['s' => $o->subject], $found), 's'));
        self::assertSame(39, $found[0]->impact, '20 + round(12 × log10(41))');
        $library = new \SeoControlCenter\Domain\Opportunities\PlaybookLibrary();
        self::assertSame('Add structured data', $library->for($found[0]->type, $found[0]->subject, $found[0]->evidence)->title);
        self::assertSame('Show who wrote each article', $library->for($found[1]->type, $found[1]->subject)->title);
        self::assertSame('Bring deep pages closer to the start page', $library->for($found[2]->type, $found[2]->subject)->title);
    }

    public function testEmptySignalsFindNothing(): void
    {
        foreach ([new QuickWinRule(), new LowCtrRule(), new RankingDeclineRule(), new TrafficDeclineRule(), new CompetitorGapRule(), new ContentGapRule(), new SiteIssueRule()] as $rule) {
            self::assertSame([], $rule->evaluate(new ProjectSignals()));
        }
    }

    private static function row(string $key, int $clicks, int $impressions, float $position): DimensionRow
    {
        return new DimensionRow($key, new Metrics($clicks, $impressions, $position));
    }

    private static function ranked(string $publicId, string $keyword, ?int $position, ?int $volume): RankedKeyword
    {
        return new RankedKeyword($publicId, $keyword, KeywordLocale::default(), '2026-09-26', $position, null, false, $position, 'https://acme.test/' . $publicId, [], $volume);
    }
}
