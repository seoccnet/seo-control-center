<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Overview;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\AiVisibility\AiOverviewRepository;
use SeoControlCenter\Modules\Analytics\Ga4Repository;
use SeoControlCenter\Modules\Annotations\AnnotationRepository;
use SeoControlCenter\Modules\Annotations\ChangeImpactService;
use SeoControlCenter\Modules\Backlinks\BacklinkHistory;
use SeoControlCenter\Modules\Indexing\IndexingService;
use SeoControlCenter\Modules\Local\LocalRepository;
use SeoControlCenter\Modules\Pages\PageInsights;
use SeoControlCenter\Modules\Pages\PageRepository;
use SeoControlCenter\Modules\Research\AiMentionHistory;
use SeoControlCenter\Modules\Research\ResearchRepository;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;
use SeoControlCenter\Modules\WebVitals\WebVitalsRepository;

/** Called after project authorization. Only persisted reads, no provider dependencies. */
final readonly class OverviewEvidence
{
    public function __construct(
        private SearchPerformanceReader $search,
        private Ga4Repository $ga4,
        private RankingRepository $rankings,
        private KeywordRepository $keywords,
        private ResearchRepository $research,
        private PageRepository $pages,
        private AiOverviewRepository $ai,
        private AiMentionHistory $mentions,
        private \SeoControlCenter\Modules\Research\BrandMentionRepository $brands,
        private BacklinkHistory $backlinks,
        private SiteAuditRepository $audits,
        private WebVitalsRepository $vitals,
        private IndexingService $indexing,
        private AnnotationRepository $annotations,
        private ChangeImpactService $changes,
        private LocalRepository $local,
        private Clock $clock,
    ) {}

    public function fallbackRange(Project $project, int $days): DateRange
    {
        $end = $this->ga4->metadata($project->id)['to'] ?? $this->clock->now()->modify('-1 day')->format('Y-m-d');
        return DateRange::endingOn($end, $days);
    }

    /** @return array<string, mixed> */
    public function build(Project $project, DateRange $range): array
    {
        $covered = $this->search->coveredDays($project->id, $range);
        $previousCovered = $this->search->coveredDays($project->id, $range->previous());
        $comparable = $covered === $range->days() && $previousCovered === $range->days();
        $movers = [];
        foreach ([PerformanceDimension::Query, PerformanceDimension::Page] as $dimension) {
            $compared = $comparable ? $this->search->compared($project->id, $dimension, $range, 500) : ['rows' => [], 'total' => 0];
            $movers[$dimension->value] = [...OverviewCalculations::movers($compared['rows']), 'total' => $compared['total'], 'loaded' => count($compared['rows'])];
        }
        $queries = $this->search->top($project->id, PerformanceDimension::Query, $range, 500);
        $pageRows = $this->search->top($project->id, PerformanceDimension::Page, $range, 500);
        $pageMetrics = array_column($pageRows, 'metrics', 'key');
        $joint = $this->pages->queries($project->id, $range);
        $near = array_values(array_filter($queries, static fn($row): bool => $row->metrics->position >= 4 && $row->metrics->position <= 20 && $row->metrics->impressions >= 200));
        usort($near, static fn($a, $b): int => [$b->metrics->impressions, $a->key] <=> [$a->metrics->impressions, $b->key]);
        $settings = $this->rankings->settings($project->id);
        $latest = $this->rankings->latestDay($project->id, $settings->device);
        $previous = $latest === null ? null : $this->rankings->dayOnOrBefore($project->id, $settings->device, (new \DateTimeImmutable($latest->date))->modify('-7 days')->format('Y-m-d'));
        $buckets = $latest === null ? [] : $this->rankings->positionBuckets($project->id, $project->workspaceId, $settings->device, $latest->date);
        $matched = $latest === null || $previous === null ? [] : $this->rankings->positionBuckets($project->id, $project->workspaceId, $settings->device, $latest->date, $previous->date);
        $prior = $latest === null || $previous === null ? [] : $this->rankings->positionBuckets($project->id, $project->workspaceId, $settings->device, $previous->date, $latest->date);
        $aiDate = $this->ai->latestDate($project->id, $settings->device);
        $aiPrevious = $aiDate === null ? null : $this->ai->dateOnOrBefore($project->id, $settings->device, (new \DateTimeImmutable($aiDate))->modify('-7 days')->format('Y-m-d'));
        $brandSettings = $this->brands->settings($project);
        $brandHistory = $brandSettings === null ? [] : array_values(array_filter($this->brands->history($project, $brandSettings->key($project), 12), static fn($check): bool => $check->status === 'completed'));
        $notes = $this->annotations->list($project, null, null, 3, 0);
        $changes = array_map(fn($note): array => ['note' => $note, 'impact' => $this->changes->compare($project, $note->publicId, 14)], $notes);
        $audit = $this->audits->latest($project->id, 'completed');
        $issues = $audit === null ? [] : $this->audits->issues($audit->id, null, 0, 500);
        $trafficIssues = [];
        foreach ($issues as $issue) {
            $metrics = $pageMetrics[$issue['url']] ?? null;
            if ($metrics !== null && $metrics->impressions > 0) {
                $trafficIssues[] = [...$issue, 'metrics' => $metrics];
            }
        }
        usort($trafficIssues, static fn(array $a, array $b): int => [$b['metrics']->clicks, $b['metrics']->impressions, $a['url']] <=> [$a['metrics']->clicks, $a['metrics']->impressions, $b['url']]);
        $records = [...$this->vitals->list($project->id, 100, 'PHONE'), ...$this->vitals->list($project->id, 100, 'DESKTOP')];
        $localSettings = $this->local->settings($project->id);
        $localDate = $localSettings === null ? null : $this->local->latestDate($project->id);
        $localPositions = $localDate === null ? [] : $this->local->rankings($project->id, $localDate);
        $localBefore = $localDate === null ? null : $this->local->dateBefore($project->id, $localDate);
        $localPrior = $localBefore === null ? [] : $this->local->rankings($project->id, $localBefore);
        $common = array_intersect_key($localPositions, $localPrior);
        $top3 = static fn(array $rows): int => count(array_filter($rows, static fn(array $r): bool => $r['position'] !== null && $r['position'] <= 3));

        return [
            'range' => $range, 'covered' => $covered, 'previousCovered' => $previousCovered, 'comparable' => $comparable,
            'movers' => $movers, 'querySample' => count($queries), 'pageSample' => count($pageRows),
            'ctrActions' => $covered === $range->days() ? OverviewCalculations::ctrActions($queries) : [],
            'near' => array_slice($near, 0, 5), 'nearCount' => count($near),
            'cannibalization' => array_slice((new PageInsights())->cannibalization($joint), 0, 3), 'jointSample' => count($joint),
            'analytics' => $this->analytics($project, $range, $pageMetrics, $covered === $range->days()),
            'ranking' => ['date' => $latest?->date, 'previousDate' => $previous?->date, 'device' => $settings->device->value, 'counts' => $buckets, 'matched' => $matched, 'prior' => $prior, 'sample' => array_sum($buckets), 'common' => array_sum($matched), 'unrepresented' => max(0, $this->keywords->count($project->id) - array_sum($buckets))],
            'demand' => $this->demand($project),
            'ai' => ['date' => $aiDate, 'previousDate' => $aiPrevious, 'current' => $aiDate === null ? null : $this->ai->summary($project->id, $settings->device, $aiDate), 'previous' => $aiPrevious === null ? null : $this->ai->summary($project->id, $settings->device, $aiPrevious), 'device' => $settings->device->value],
            'mentions' => $this->mentions->history($project->id, 4), 'backlinks' => $this->backlinks->history($project->id, 2),
            'brand' => ['settings' => $brandSettings, 'history' => array_slice($brandHistory, 0, 3)],
            'lost' => $this->research->find($project->id, 'bl_lost'), 'new' => $this->research->find($project->id, 'bl_new'),
            'audit' => $audit, 'issues' => array_slice($trafficIssues, 0, 5), 'issueSample' => count($issues),
            'rendered' => $this->research->find($project->id, 'rendered_audit'),
            'indexing' => $this->indexing->listing($project, '', 'problem', 1), 'vitals' => $records,
            'changes' => $changes,
            'local' => $localSettings === null ? null : ['settings' => $localSettings, 'date' => $localDate, 'previousDate' => $localBefore, 'sample' => count($localPositions), 'top3' => $top3($localPositions), 'common' => count($common), 'delta' => $common === [] ? null : $top3($common) - $top3(array_intersect_key($localPrior, $common)), 'profiles' => $this->local->profiles($project->id, 2)],
            'asOf' => $this->clock->now()->format('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string, \SeoControlCenter\Domain\SearchConsole\Metrics> $searchPages
     * @return array<string, mixed>
     */
    private function analytics(Project $project, DateRange $range, array $searchPages, bool $searchComplete): array
    {
        $metadata = $this->ga4->metadata($project->id, $range);
        $previousMetadata = $this->ga4->metadata($project->id, $range->previous());
        $covered = $this->ga4->coveredDays($project->id, $range);
        $previousCovered = $this->ga4->coveredDays($project->id, $range->previous());
        $complete = $metadata !== null && $covered === $range->days() && !$metadata['limited'] && !$metadata['thresholded'];
        $comparable = $complete && $previousMetadata !== null && $previousCovered === $range->days() && !$previousMetadata['limited'] && !$previousMetadata['thresholded'] && $metadata['currency'] === $previousMetadata['currency'] && $metadata['timezone'] === $previousMetadata['timezone'];
        $landingPages = $this->ga4->landingPages($project->id, $range, 100);
        $rows = [];
        foreach ($landingPages as $row) {
            if (isset($searchPages[$row['url']]) && $searchPages[$row['url']]->impressions >= 100 && $row['sessions'] >= 10) {
                $rows[] = [...$row, 'search' => $searchPages[$row['url']], 'eventRate' => $row['sessions'] > 0 ? $row['conversions'] / $row['sessions'] : null];
            }
        }
        $canRank = $complete && $searchComplete;
        usort($rows, static fn(array $a, array $b): int => $canRank ? [$a['eventRate'], $b['search']->impressions, $a['url']] <=> [$b['eventRate'], $a['search']->impressions, $b['url']] : [$b['search']->impressions, $a['url']] <=> [$a['search']->impressions, $b['url']]);
        return ['propertyId' => $this->ga4->propertyId($project->id), 'metadata' => $metadata, 'covered' => $covered, 'previousCovered' => $previousCovered, 'complete' => $complete, 'canRank' => $canRank, 'comparable' => $comparable, 'totals' => $covered > 0 ? $this->ga4->totals($project->id, $range) : null, 'previous' => $comparable ? $this->ga4->totals($project->id, $range->previous()) : null, 'rows' => array_slice($rows, 0, 5), 'sample' => count($rows), 'loaded' => count($landingPages)];
    }

    /** @return list<array<string, mixed>> */
    private function demand(Project $project): array
    {
        $tracked = [];
        foreach ($this->keywords->page($project->id, $project->workspaceId, max(1, $this->keywords->count($project->id)), 0) as $keyword) {
            $tracked[$keyword->locale->locationCode . ':' . $keyword->locale->languageCode . ':' . mb_strtolower($keyword->keyword)] = true;
        }
        $groups = [];
        foreach (['ranked', 'ideas', 'gap'] as $kind) {
            foreach ($this->research->forKind($project->id, $kind) as $result) {
                if ($result->status !== 'done' || $result->fetchedAt === null) {
                    continue;
                }
                $locale = $result->locale();
                $id = $locale->locationCode . ':' . $locale->languageCode;
                $groups[$id] ??= ['locale' => $locale, 'rows' => [], 'dates' => []];
                $groups[$id]['dates'][] = $result->fetchedAt;
                foreach ($result->keywords() as $keyword) {
                    $key = mb_strtolower($keyword->keyword);
                    $old = $groups[$id]['rows'][$key] ?? null;
                    if ($old === null || $old['fetchedAt'] < $result->fetchedAt) {
                        $groups[$id]['rows'][$key] = ['keyword' => $keyword, 'tracked' => isset($tracked[$id . ':' . $key]), 'kind' => $kind, 'subject' => $result->subject, 'fetchedAt' => $result->fetchedAt];
                    } elseif ($kind === 'gap') {
                        $groups[$id]['rows'][$key]['kind'] = 'gap';
                    }
                }
            }
        }
        $output = [];
        foreach ($groups as $group) {
            $rows = array_values($group['rows']);
            $untracked = array_values(array_filter($rows, static fn(array $r): bool => !$r['tracked']));
            usort($untracked, static fn(array $a, array $b): int => [$b['keyword']->searchVolume ?? -1, $a['keyword']->keyword] <=> [$a['keyword']->searchVolume ?? -1, $b['keyword']->keyword]);
            $sum = static fn(array $list): int => array_sum(array_map(static fn(array $r): int => $r['keyword']->searchVolume ?? 0, $list));
            $output[] = ['locale' => $group['locale'], 'sample' => count($rows), 'tracked' => count($rows) - count($untracked), 'untracked' => count($untracked), 'trackedVolume' => $sum(array_values(array_filter($rows, static fn(array $r): bool => $r['tracked']))), 'untrackedVolume' => $sum($untracked), 'unknownVolume' => count(array_filter($rows, static fn(array $r): bool => $r['keyword']->searchVolume === null)), 'rows' => array_slice($untracked, 0, 4), 'from' => min($group['dates']), 'to' => max($group['dates'])];
        }
        return $output;
    }
}
