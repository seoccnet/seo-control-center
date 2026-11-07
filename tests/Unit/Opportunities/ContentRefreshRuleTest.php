<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\PlaybookLibrary;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\Rules\ContentRefreshRule;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;

/** Refresh aging content (0.41.0): articles with demand whose sitemap date is over a year old. */
final class ContentRefreshRuleTest extends TestCase
{
    public function testAgingArticlesWithDemandAreFoundAndOthersAreLeftAlone(): void
    {
        $pages = [
            new DimensionRow('https://s.test/blog/mold-guide', new Metrics(120, 4000, 6.2)),
            new DimensionRow('https://s.test/blog/fresh-guide', new Metrics(90, 3000, 5.0)),
            new DimensionRow('https://s.test/blog/no-date', new Metrics(80, 2500, 7.0)),
            new DimensionRow('https://s.test/blog/quiet', new Metrics(1, 120, 9.0)),
            new DimensionRow('https://s.test/blog/deep', new Metrics(5, 900, 34.0)),
            new DimensionRow('https://s.test/seeds/blue-dream', new Metrics(200, 9000, 4.0)),
            new DimensionRow('https://s.test/blog/topics/mold', new Metrics(30, 1000, 8.0)),
            new DimensionRow('https://s.test/blog', new Metrics(300, 8000, 3.0)),
        ];
        $old = ['https://s.test/blog/mold-guide' => '2024-08-01', 'https://s.test/blog/fresh-guide' => '2026-06-01', 'https://s.test/blog/quiet' => '2023-01-01', 'https://s.test/blog/deep' => '2023-01-01', 'https://s.test/seeds/blue-dream' => '2022-01-01', 'https://s.test/blog/topics/mold' => '2022-01-01', 'https://s.test/blog' => '2022-01-01'];
        $signals = new ProjectSignals(searchConsoleWindow: '2026-09-27 – 2026-10-24', pages: $pages, analysisDate: '2026-10-25', pageUpdates: $old);
        $found = (new ContentRefreshRule())->evaluate($signals);

        self::assertSame(['page:https://s.test/blog/mold-guide'], array_map(static fn($o): string => $o->subject, $found), 'Fresh, undated, quiet, out-of-reach, product, archive and folder pages are left alone.');
        $refresh = $found[0];
        self::assertSame(OpportunityType::ContentRefresh, $refresh->type);
        self::assertSame('/blog/mold-guide was last updated 2 years ago and still gets 4,000 impressions', $refresh->title);
        self::assertContains(['label' => 'Last updated', 'value' => '2024-08-01 (sitemap last-modified date)'], $refresh->evidence);
        self::assertNull($refresh->estimatedClicks, 'No click gain is invented.');
        self::assertSame([], (new ContentRefreshRule())->evaluate(new ProjectSignals(pages: $pages, analysisDate: '2026-10-25')), 'Without sitemap dates nothing is assumed old.');

        $plan = (new PlaybookLibrary())->for(OpportunityType::ContentRefresh, $refresh->subject, $refresh->evidence);
        self::assertSame(['content_refresh', 'tested', 'Tested repeatedly'], [$plan->key, $plan->tested, $plan->testedLabel()]);
        self::assertStringContainsString('last updated on 2024-08-01', $plan->items('When it applies')[0]);
        self::assertStringContainsString('Do not only change the date', $plan->cautions[0]);
    }

    public function testEveryPlanSaysHowWellTestedItsKindOfChangeIs(): void
    {
        $library = new PlaybookLibrary();
        $levels = [];
        foreach ([[OpportunityType::LowCtr, 'query:x'], [OpportunityType::ClickErosion, 'query:x'], [OpportunityType::LinkBuilding, 'spam:domains'], [OpportunityType::SiteIssue, 'site:missing_title'], [OpportunityType::SiteIssue, 'site:missing_author'], [OpportunityType::Indexing, 'indexing:https://s.test/a']] as [$type, $subject]) {
            $plan = $library->for($type, $subject);
            $levels[$plan->key] = $plan->tested;
            self::assertArrayHasKey($plan->tested, \SeoControlCenter\Domain\Opportunities\Playbook::TESTED);
            self::assertSame($plan->testedLabel(), $plan->toArray()['tested_label']);
        }
        self::assertSame(['low_ctr' => 'tested', 'click_erosion' => 'early', 'link_building:spam' => 'varies', 'site:missing_title' => 'guidance', 'site:missing_author' => 'tested', 'indexing' => 'guidance'], $levels);
    }
}
