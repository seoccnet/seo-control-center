<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support;

use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Analytics\Ga4Report;
use SeoControlCenter\Domain\Analytics\LandingPageMetrics;
use SeoControlCenter\Domain\Backlinks\BacklinkProfile;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Rankings\AiOverview;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Domain\WebVitals\WebVitalRecord;
use SeoControlCenter\Modules\AiVisibility\AiOverviewRepository;
use SeoControlCenter\Modules\Analytics\Ga4Repository;
use SeoControlCenter\Modules\Annotations\AnnotationInput;
use SeoControlCenter\Modules\Annotations\AnnotationRepository;
use SeoControlCenter\Modules\Backlinks\BacklinkHistory;
use SeoControlCenter\Modules\Pages\PageQuery;
use SeoControlCenter\Modules\Research\ResearchRepository;
use SeoControlCenter\Modules\SiteAudit\AuditPage;
use SeoControlCenter\Modules\SiteAudit\IssueType;
use SeoControlCenter\Modules\WebVitals\WebVitalsRepository;
use SeoControlCenter\Tests\Support\Fakes\InMemoryPages;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankings;
use SeoControlCenter\Tests\Support\Fakes\InMemorySiteAudit;

/** Explicit seeded evidence for HTTP tests and local visual verification only. */
final class OverviewFixture
{
    public static function scenario(bool $seed = true): Scenario
    {
        $s = new Scenario(clock: new FrozenClock(new \DateTimeImmutable('2026-10-07 12:00:00 UTC')));
        if ($seed) {
            self::seed($s);
        }
        return $s;
    }

    public static function seed(Scenario $s): void
    {
        $c = $s->container;
        $p = $s->acmeProject;
        $url = 'https://' . $p->primaryDomain;
        $range = new DateRange('2026-08-12', '2026-10-06');
        $performance = $c->make(SearchPerformanceStore::class);
        $gaRows = [];
        foreach ($range->eachDay() as $date) {
            $current = $date >= '2026-09-09';
            $performance->upsertDailyTotals($p->id, [new DailyMetrics($date, new Metrics($current ? 145 : 120, 6000, $current ? 7.5 : 8.1))]);
            $queries = [new DimensionRow('indoor growing', new Metrics($current ? 2 : 10, 1000, 8.0)), new DimensionRow('germination guide', new Metrics($current ? 8 : 20, 400, 12.0))];
            for ($i = 1; $i <= 6; ++$i) {
                $queries[] = new DimensionRow('growing supplies ' . $i, new Metrics($current ? 18 + $i : 10 + $i, 300, 5.0 + $i / 10));
            }
            if ($current) {
                $queries[] = new DimensionRow('new query without baseline', new Metrics(100, 1000, 2.0));
            }
            $performance->replaceDay($p->id, $date, ['query' => $queries, 'page' => [new DimensionRow($url . '/collections/indoor', new Metrics($current ? 65 : 40, 2500, 8.0)), new DimensionRow($url . '/guides/germination', new Metrics($current ? 15 : 30, 1400, 12.0))], 'country' => [], 'device' => []]);
            $gaRows[] = new LandingPageMetrics($date, $url . '/collections/indoor', 100, 8.0, 120.0);
            $gaRows[] = new LandingPageMetrics($date, $url . '/guides/germination', 30, 0.0, 0.0);
        }
        $ga = $c->make(Ga4Repository::class);
        $ga->link($p->id, '557368201');
        $ga->saveReport($p->id, $range, new Ga4Report($gaRows, 'USD', 'America/Los_Angeles', false, false), '557368201');
        $keywords = $c->make(KeywordRepository::class);
        $keywords->add($p->id, ['indoor growing', 'germination guide', 'seed storage', 'grow light', 'soil mix', 'new tracking', 'unchecked keyword'], KeywordLocale::default(), $s->owner->id);
        $rankings = $c->make(InMemoryRankings::class);
        $rankings->projects[$p->id] = ['workspace_id' => $p->workspaceId, 'domain' => $p->primaryDomain];
        $rows = $c->make(Fakes\InMemoryKeywords::class)->keywords[$p->id];
        foreach (['2026-09-29' => [8, 14, 2, 30, null], '2026-10-06' => [5, 11, 3, 20, null, 6]] as $date => $positions) {
            foreach ($positions as $i => $position) {
                $rankings->storeSnapshot(new RankSnapshot($rows[$i]['id'], $p->id, SerpDevice::Desktop, $date, $position, $position, $url . '/collections/indoor', [], [['position' => 2, 'domain' => 'competitor.test', 'url' => 'https://competitor.test/']]));
            }
            $rankings->aggregate($p->id, $p->workspaceId, SerpDevice::Desktop, $date);
        }
        $competitors = $c->make(CompetitorRepository::class);
        $competitor = $competitors->add($p->id, 'competitor.test', 'manual', $s->owner->id) ?? throw new \LogicException('Fixture competitor missing.');
        $competitors->backfill($competitor, SerpDevice::Desktop, ['2026-09-29', '2026-10-06']);
        foreach (['2026-09-29', '2026-10-06'] as $date) {
            $competitors->aggregate($p->id, $p->workspaceId, SerpDevice::Desktop, $date);
        }
        $research = $c->make(ResearchRepository::class);
        $research->queue($p->id, $p->workspaceId, 'gap', $competitor->publicId, ['location' => 2840, 'language' => 'en'], $s->owner->id);
        $research->complete($p->id, 'gap', $competitor->publicId, array_map(static fn($k): array => $k->toArray(), [new ResearchKeyword('indoor growing', 1200), new ResearchKeyword('seed storage temperature', 800), new ResearchKeyword('germination troubleshooting', 600), new ResearchKeyword('unknown demand')]));
        $c->make(OpportunityRepository::class)->sync($p->id, [new Opportunity(OpportunityType::SiteIssue, 'noindex', 'Review noindex on the indoor collection', 'The saved crawl found noindex on a page with recorded search demand.', [['label' => 'Page', 'value' => $url . '/collections/indoor'], ['label' => 'Impressions', 'value' => '70,000']], 70, 90, 1)]);
        $c->make(AnnotationRepository::class)->save($p, new AnnotationInput('2026-09-20', 'content', 'Reworked the germination guide', 'Added a troubleshooting section.', null), $s->owner->id, null);
        $pages = $c->make(InMemoryPages::class);
        $pages->queryRows[$p->id] = [new PageQuery('indoor growing', $url . '/collections/indoor', new Metrics(56, 28000, 8.0)), new PageQuery('indoor growing', $url . '/guides/germination', new Metrics(25, 5000, 12.0))];
        $history = $c->make(BacklinkHistory::class);
        $history->record($p->id, $p->workspaceId, '2026-09-06', [new BacklinkProfile($p->primaryDomain, backlinks: 840, referringDomains: 90)]);
        $history->record($p->id, $p->workspaceId, '2026-10-06', [new BacklinkProfile($p->primaryDomain, backlinks: 890, referringDomains: 94)]);
        $ai = $c->make(AiOverviewRepository::class);
        foreach (['2026-09-29', '2026-10-06'] as $date) {
            $ai->record($p->id, $rows[0]['id'], SerpDevice::Desktop, $date, new AiOverview([]), $date === '2026-10-06' ? 1 : null, $date === '2026-10-06' ? $url . '/collections/indoor' : null);
            $ai->record($p->id, $rows[1]['id'], SerpDevice::Desktop, $date, null, null, null);
        }
        $audits = $c->make(InMemorySiteAudit::class);
        $audit = $audits->create($p->id, $p->workspaceId, $url . '/', 10, 'manual', $s->owner->id);
        $audits->pages[$audit->id][1] = new AuditPage(1, $url . '/collections/indoor', 1, 'fetched', 200, 'text/html', noindex: true);
        $audits->finish($audit->id, 'completed', 80, ['error' => 0, 'warning' => 1, 'notice' => 0], [['pageId' => 1, 'type' => IssueType::Noindex, 'detail' => null]], null);
        $c->make(WebVitalsRepository::class)->save($p->id, new WebVitalRecord($url . '/collections/indoor', 3200, 180, 0.08, '2026-09-08', '2026-10-05', '2026-10-06 12:00:00'));
    }
}
