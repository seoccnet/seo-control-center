<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Reports;

use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Rankings\RankingDay;
use SeoControlCenter\Domain\Rankings\RankingMove;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\DimensionRows;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Bing\BingService;
use SeoControlCenter\Modules\SiteAudit\IssueType;
use SeoControlCenter\Modules\SiteAudit\SiteAudit;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;

/**
 * Collects a project's report for one period from stored data only: search
 * (Google + Bing, with the period before), top pages and queries, rankings
 * and open opportunities. The caller has checked access to the project.
 */
final readonly class ReportBuilder
{
    public const int TOP_ROWS = 10;
    public const int MOVES = 5;
    public const int OPPORTUNITIES = 5;
    private const int MERGE_ROWS = 50;

    public function __construct(
        private SearchPerformanceReader $google,
        private BingService $bing,
        private RankingRepository $rankings,
        private OpportunityRepository $opportunities,
        private SiteAuditRepository $siteAudits,
        private \SeoControlCenter\Modules\Analytics\Ga4Repository $ga4,
        private \SeoControlCenter\Modules\Research\ResearchRepository $research,
    ) {}

    /**
     * @return array{
     *     project: Project, frequency: string, period: DateRange, previousPeriod: DateRange, label: string,
     *     search: array{google: ?Metrics, googlePrevious: ?Metrics, bing: ?Metrics, bingPrevious: ?Metrics, combined: Metrics, combinedPrevious: Metrics}|null,
     *     queries: list<DimensionRow>, pages: list<DimensionRow>, clicks: list<array{0: string, 1: float}>,
     *     rankings: array{day: RankingDay, previous: ?RankingDay, winners: list<RankingMove>, losers: list<RankingMove>, device: string}|null,
     *     openOpportunities: int, opportunities: list<StoredOpportunity>,
     *     analytics: array{metadata: array{from: string, to: string, currency: string, timezone: string, limited: bool, thresholded: bool, fetched_at: string}, rows: list<array{url: string, sessions: int, conversions: float, revenue: float}>, partial: bool}|null,
     *     siteAudit: array{date: string, score: ?int, pages: int, errors: int, warnings: int, previousScore: ?int, previousDate: ?string, issues: list<array{label: string, severity: string, pages: int}>}|null
     *     , renderedAudit: array{date: string, pages: int, issues: list<array{type: string, label: string, severity: string, advice: string, pages: int, examples: list<string>, effort: int}>}|null
     * }
     */
    public function build(Project $project, string $frequency, DateRange $period): array
    {
        $previous = ReportPeriod::before($frequency, $period);
        $hasGoogle = $this->google->dataSpan($project->id) !== null;
        $hasBing = $this->bing->hasData($project->id);

        $search = null;
        $queries = $pages = [];
        $byDate = [];
        if ($hasGoogle || $hasBing) {
            $google = $hasGoogle ? $this->google->totals($project->id, $period) : null;
            $googlePrevious = $hasGoogle ? $this->google->totals($project->id, $previous) : null;
            $bing = $hasBing ? $this->bing->totals($project->id, $period) : null;
            $bingPrevious = $hasBing ? $this->bing->totals($project->id, $previous) : null;
            $search = [
                'google' => $google,
                'googlePrevious' => $googlePrevious,
                'bing' => $bing,
                'bingPrevious' => $bingPrevious,
                'combined' => self::combine($google, $bing),
                'combinedPrevious' => self::combine($googlePrevious, $bingPrevious),
            ];
            foreach ($hasGoogle ? $this->google->daily($project->id, $period) : [] as $day) {
                $byDate[$day->date] = (float) $day->metrics->clicks;
            }
            foreach ($hasBing ? $this->bing->daily($project->id, $period) : [] as $day) {
                $byDate[$day->date] = ($byDate[$day->date] ?? 0.0) + $day->metrics->clicks;
            }
            foreach ([PerformanceDimension::Query, PerformanceDimension::Page] as $dimension) {
                $rows = [];
                if ($hasGoogle) {
                    $rows[] = $this->google->top($project->id, $dimension, $period, self::MERGE_ROWS);
                }
                if ($hasBing) {
                    $rows[] = $this->bing->top($project->id, $dimension, $period, self::MERGE_ROWS);
                }
                $merged = array_slice(DimensionRows::merge(...$rows), 0, self::TOP_ROWS);
                $dimension === PerformanceDimension::Query ? $queries = $merged : $pages = $merged;
            }
        }

        $analytics = $this->ga4->metadata($project->id, $period);
        return [
            'project' => $project,
            'frequency' => $frequency,
            'period' => $period,
            'previousPeriod' => $previous,
            'label' => ReportPeriod::label($frequency, $period),
            'search' => $search,
            'queries' => $queries,
            'pages' => $pages,
            'clicks' => array_map(static fn(string $d): array => [$d, $byDate[$d] ?? 0.0], $period->eachDay()),
            'rankings' => $this->rankings($project, $period),
            'openOpportunities' => $this->opportunities->count($project->id, StoredOpportunity::OPEN),
            'opportunities' => $this->opportunities->list($project->id, StoredOpportunity::OPEN, null, self::OPPORTUNITIES, 0),
            'siteAudit' => $this->siteAudit($project),
            'renderedAudit' => ($rendered = $this->research->find($project->id, \SeoControlCenter\Modules\SiteAudit\RenderedAuditService::KIND)) !== null && $rendered->fetchedAt !== null ? ['date' => $rendered->fetchedAt, 'pages' => count($rendered->items), 'issues' => \SeoControlCenter\Modules\SiteAudit\RenderedFindings::issues($rendered)] : null,
            'analytics' => $analytics === null ? null : ['metadata' => [...$analytics, 'from' => $period->fromString(), 'to' => $period->toString()], 'rows' => $this->ga4->landingPages($project->id, $period, 10), 'partial' => $this->ga4->coveredDays($project->id, $period) < $period->days()],
        ];
    }

    /**
     * The latest completed site audit, compared with the one before it.
     *
     * @return array{date: string, score: ?int, pages: int, errors: int, warnings: int, previousScore: ?int, previousDate: ?string, issues: list<array{label: string, severity: string, pages: int}>}|null
     */
    private function siteAudit(Project $project): ?array
    {
        $completed = array_values(array_filter($this->siteAudits->history($project->id, 10), static fn(SiteAudit $a): bool => $a->isCompleted()));
        $audit = $completed[0] ?? null;
        if ($audit === null) {
            return null;
        }
        $previous = $completed[1] ?? null;
        $issues = [];
        foreach ($this->siteAudits->issueCounts($audit->id) as $value => $count) {
            $type = IssueType::tryFrom($value);
            if ($type !== null && $type->severity() !== 'notice') {
                $issues[] = ['label' => $type->label(), 'severity' => $type->severity(), 'pages' => $count, 'rank' => $type->severityRank()];
            }
        }
        usort($issues, static fn(array $a, array $b): int => [$a['rank'], -$a['pages']] <=> [$b['rank'], -$b['pages']]);

        return [
            'date' => substr((string) ($audit->finishedAt ?? $audit->createdAt), 0, 10),
            'score' => $audit->healthScore,
            'pages' => $audit->pagesCrawled,
            'errors' => $audit->errors,
            'warnings' => $audit->warnings,
            'previousScore' => $previous?->healthScore,
            'previousDate' => $previous !== null ? substr((string) ($previous->finishedAt ?? $previous->createdAt), 0, 10) : null,
            'issues' => array_map(static fn(array $i): array => ['label' => $i['label'], 'severity' => $i['severity'], 'pages' => $i['pages']], array_slice($issues, 0, 5)),
        ];
    }

    /**
     * The last rank check of the period, compared with the last one before it.
     *
     * @return array{day: RankingDay, previous: ?RankingDay, winners: list<RankingMove>, losers: list<RankingMove>, device: string}|null
     */
    private function rankings(Project $project, DateRange $period): ?array
    {
        $device = $this->rankings->settings($project->id)->device;
        $day = $this->rankings->dayOnOrBefore($project->id, $device, $period->toString());
        if ($day === null || $day->date < $period->fromString()) {
            return null;
        }
        $before = (new \DateTimeImmutable($period->fromString()))->modify('-1 day')->format('Y-m-d');
        $previous = $this->rankings->dayOnOrBefore($project->id, $device, $before);
        $winners = $losers = [];
        if ($previous !== null) {
            $moves = $this->rankings->moves($project->id, $device, $previous->date, $day->date);
            $winners = array_values(array_filter($moves, static fn(RankingMove $m): bool => $m->change() > 0));
            $losers = array_values(array_filter($moves, static fn(RankingMove $m): bool => $m->change() < 0));
            usort($winners, static fn(RankingMove $a, RankingMove $b): int => [$b->change(), $a->keyword] <=> [$a->change(), $b->keyword]);
            usort($losers, static fn(RankingMove $a, RankingMove $b): int => [$a->change(), $a->keyword] <=> [$b->change(), $b->keyword]);
        }

        return [
            'day' => $day,
            'previous' => $previous,
            'winners' => array_slice($winners, 0, self::MOVES),
            'losers' => array_slice($losers, 0, self::MOVES),
            'device' => $device->value,
        ];
    }

    /**
     * Google plus Bing clicks and impressions; the position stays Google's.
     */
    private static function combine(?Metrics $google, ?Metrics $bing): Metrics
    {
        if ($google === null) {
            return new Metrics($bing->clicks ?? 0, $bing->impressions ?? 0, 0.0);
        }

        return new Metrics($google->clicks + ($bing->clicks ?? 0), $google->impressions + ($bing->impressions ?? 0), $google->position);
    }
}
