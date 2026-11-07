<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Annotations;

use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Analytics\Ga4Repository;
use SeoControlCenter\Modules\Pages\PageRepository;

/** Read only; callers authorize the project before passing it here. */
final readonly class ChangeImpactService
{
    public const string NOTICE = 'Observed changes are associations, not proof that the edit caused a result. Seasonality, competitors, search updates and other edits can affect performance. Missing data is unavailable, not zero. Google dates use Pacific Time; GA4 uses its property timezone. The change day is excluded.';

    public function __construct(private AnnotationService $annotations, private SearchPerformanceReader $google, private PageRepository $pages, private Ga4Repository $ga4, private Clock $clock) {}

    /** @return array<string, mixed> */
    public function compare(Project $project, string $id, int $days = 28): array
    {
        if (!in_array($days, [14, 28, 90], true)) {
            throw new HttpException(400, 'Choose 14, 28 or 90 days.');
        }
        $annotation = $this->annotations->get($project, $id);
        $date = new DateRange($annotation->date, $annotation->date);
        $before = DateRange::endingOn($date->from->modify('-1 day')->format('Y-m-d'), $days);
        $after = new DateRange($date->from->modify('+1 day')->format('Y-m-d'), $date->from->modify('+' . $days . ' days')->format('Y-m-d'));
        $elapsed = $after->toString() < $this->clock->now()->format('Y-m-d');
        $previous = $this->period($project, $annotation->url, $before);
        $following = $this->period($project, $annotation->url, $after);
        $metrics = [];
        foreach (['clicks', 'impressions', 'ctr', 'position'] as $key) {
            $metrics[$key] = self::change($previous['google'][$key] ?? null, $following['google'][$key] ?? null, $elapsed && $previous['google_complete'] && $following['google_complete']);
        }
        $status = 'Insufficient data';
        if ($metrics['clicks']['available'] && $metrics['impressions']['available']) {
            $directions = [($metrics['clicks']['delta'] ?? 0) <=> 0, ($metrics['impressions']['delta'] ?? 0) <=> 0];
            $status = in_array(1, $directions, true) && in_array(-1, $directions, true) ? 'Mixed' : (in_array(1, $directions, true) ? 'Improved' : (in_array(-1, $directions, true) ? 'Declined' : 'Unchanged'));
        }
        $analytics = [];
        foreach (['sessions', 'conversions', 'revenue'] as $key) {
            $analytics[$key] = self::change($previous['analytics'][$key] ?? null, $following['analytics'][$key] ?? null, $elapsed && $previous['analytics_complete'] && $following['analytics_complete']);
        }
        $context = $this->annotations->context($project, new DateRange($before->fromString(), $after->toString()), $annotation->url);
        return ['annotation' => $annotation->present(), 'days' => $days, 'elapsed' => $elapsed, 'status' => $status, 'status_basis' => 'Direction of Google clicks and impressions only; not a significance test or a judgment of the edit. CTR and average position are separate evidence.', 'before' => $previous, 'after' => $following, 'google_changes' => $metrics, 'analytics_changes' => $analytics, 'notice' => self::NOTICE, 'other_changes' => array_map(static fn(Annotation $a): array => $a->present(), array_values(array_filter($context, static fn(Annotation $a): bool => $a->publicId !== $id))), 'context_notice' => 'Most recent 100 matching notes across both windows, excluding this note. Page comparisons include site-wide notes. Site-wide comparisons include all pages. No notes does not mean no other changes occurred.', 'bing_notice' => 'Bing uses weekly page buckets and is not included in these daily comparisons.', 'ranking_notice' => 'Average position is impression-weighted Google Search Console position, not a tracked-keyword rank or a fixed keyword cohort. Query mix can change.'];
    }

    /**
     * Countdown and early read for each listed change. Settled changes (older
     * than ImpactWindow::SETTLED_DAYS) get no early read; use compare() instead.
     *
     * @param list<Annotation> $annotations
     * @return array<string, array{window: ImpactWindow, early: ?array<string, mixed>}>
     */
    public function timeline(Project $project, array $annotations): array
    {
        $today = $this->clock->now()->format('Y-m-d');
        $last = $this->google->dataSpan($project->id)['last'] ?? null;
        $result = [];
        foreach ($annotations as $annotation) {
            $window = new ImpactWindow($annotation->kind, $annotation->date, $today);
            $result[$annotation->publicId] = ['window' => $window, 'early' => $last !== null && $window->phase !== ImpactWindow::SETTLED ? $this->earlyRead($project, $annotation, $last) : null];
        }

        return $result;
    }

    /**
     * Equal Google periods on both sides of the change day, each as long as
     * the imported data after the change allows (7 to 28 days). Null before
     * 7 days of data exist; `complete` is false when either side lacks days.
     *
     * @return array{days: int, complete: bool, before?: array{clicks: int, impressions: int, ctr: ?float, position: ?float}, after?: array{clicks: int, impressions: int, ctr: ?float, position: ?float}, clicks?: ?float, impressions?: ?float, ctr?: ?float, position?: ?float}|null
     */
    public function earlyRead(Project $project, Annotation $annotation, string $dataLast): ?array
    {
        $day = new \DateTimeImmutable($annotation->date);
        $days = min(ImpactWindow::COMPARISON_DAYS, (int) $day->diff(new \DateTimeImmutable($dataLast))->format('%r%a'));
        if ($days < 7) {
            return null;
        }
        $before = DateRange::endingOn($day->modify('-1 day')->format('Y-m-d'), $days);
        $after = new DateRange($day->modify('+1 day')->format('Y-m-d'), $day->modify('+' . $days . ' days')->format('Y-m-d'));
        $previous = $this->googlePeriod($project, $annotation->url, $before);
        $following = $this->googlePeriod($project, $annotation->url, $after);
        if ($previous === null || $following === null) {
            return ['days' => $days, 'complete' => false];
        }
        $both = $previous->impressions > 0 && $following->impressions > 0;

        return [
            'days' => $days,
            'complete' => true,
            'before' => self::measured($previous),
            'after' => self::measured($following),
            'clicks' => $previous->clicks > 0 ? ($following->clicks - $previous->clicks) / $previous->clicks * 100 : null,
            'impressions' => $previous->impressions > 0 ? ($following->impressions - $previous->impressions) / $previous->impressions * 100 : null,
            'ctr' => $both ? ($following->ctr() - $previous->ctr()) * 100 : null,
            'position' => $both ? $following->position - $previous->position : null,
        ];
    }

    /** Google metrics of a complete period (every day imported), or null. */
    private function googlePeriod(Project $project, ?string $url, DateRange $range): ?Metrics
    {
        $days = $url === null ? count($this->google->daily($project->id, $range)) : $this->pages->importedPageDays($project->id, $range);
        if ($days !== $range->days()) {
            return null;
        }

        return $url === null ? $this->google->totals($project->id, $range) : $this->pages->find($project->id, $url, $range)?->google;
    }

    /** @return array{from: string, to: string, expected_days: int, google_days: int, google_complete: bool, google: ?array{clicks: int, impressions: int, ctr: ?float, position: ?float}, analytics_days: int, analytics_complete: bool, analytics: ?array{sessions: int, conversions: float, revenue: float}, analytics_metadata: ?array{from: string, to: string, currency: string, timezone: string, limited: bool, thresholded: bool, fetched_at: string}} */
    private function period(Project $project, ?string $url, DateRange $range): array
    {
        $googleDays = $url === null ? count($this->google->daily($project->id, $range)) : $this->pages->importedPageDays($project->id, $range);
        $metrics = $url === null ? ($googleDays > 0 ? $this->google->totals($project->id, $range) : null) : $this->pages->find($project->id, $url, $range)?->google;
        // A completed import may omit anonymized/truncated URL rows: absence is not a measured zero.
        $googleComplete = $googleDays === $range->days() && $metrics !== null;
        $metadata = $url === null ? null : $this->ga4->metadata($project->id, $range);
        $analyticsDays = $metadata === null ? 0 : $this->ga4->coveredDays($project->id, $range);
        $analyticsComplete = $metadata !== null && $analyticsDays === $range->days() && !$metadata['limited'] && !$metadata['thresholded'];
        $row = $metadata === null || $url === null ? null : ($this->ga4->landingPages($project->id, $range, 1, $url)[0] ?? null);
        // Successful complete GA4 imports without this landing page are a measured zero.
        $analytics = $row === null ? ($analyticsComplete ? ['sessions' => 0, 'conversions' => 0.0, 'revenue' => 0.0] : null) : ['sessions' => $row['sessions'], 'conversions' => $row['conversions'], 'revenue' => $row['revenue']];
        return ['from' => $range->fromString(), 'to' => $range->toString(), 'expected_days' => $range->days(), 'google_days' => $googleDays, 'google_complete' => $googleComplete, 'google' => self::metrics($metrics), 'analytics_days' => $analyticsDays, 'analytics_complete' => $analyticsComplete, 'analytics' => $analytics, 'analytics_metadata' => $metadata];
    }

    /** @return array{clicks: int, impressions: int, ctr: ?float, position: ?float}|null */
    private static function metrics(?Metrics $metrics): ?array
    {
        return $metrics === null ? null : self::measured($metrics);
    }

    /** @return array{clicks: int, impressions: int, ctr: ?float, position: ?float} */
    private static function measured(Metrics $metrics): array
    {
        return ['clicks' => $metrics->clicks, 'impressions' => $metrics->impressions, 'ctr' => $metrics->impressions > 0 ? $metrics->ctr() * 100 : null, 'position' => $metrics->impressions > 0 ? $metrics->position : null];
    }

    /** @return array{available: bool, delta: ?float, percent: ?float} */
    private static function change(int|float|null $before, int|float|null $after, bool $complete): array
    {
        $available = $complete && $before !== null && $after !== null;
        return ['available' => $available, 'delta' => $available ? (float) ($after - $before) : null, 'percent' => $available && $before > 0 ? ($after - $before) / $before * 100 : null];
    }
}
