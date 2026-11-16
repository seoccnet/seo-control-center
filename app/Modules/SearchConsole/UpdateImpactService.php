<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole;

use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\SearchConsole\UpdateImpact;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Domain\Shared\GoogleUpdates;

/**
 * Google update impact (1.6.0): for every confirmed ranking update within
 * the imported Search Console data, the site's clicks and impressions in
 * the four weeks before the rollout against the four weeks after it, and
 * for one update the pages and queries that moved most. Stored data only.
 */
final readonly class UpdateImpactService
{
    private const int ROWS = 5000;

    public function __construct(private SearchPerformanceReader $reader) {}

    /**
     * @return array{
     *     span: ?array{first: string, last: string},
     *     updates: list<array{slug: string, name: string, start: string, end: ?string, status: string, before: ?Metrics, after: ?Metrics}>,
     *     selected: ?array{slug: string, name: string, start: string, end: ?string, status: string, before: ?Metrics, after: ?Metrics, beforeRange: DateRange, afterRange: ?DateRange, pages: array<string, list<array<string, mixed>>>, queries: array<string, list<array<string, mixed>>>}
     * }
     */
    public function report(Project $project, string $slug): array
    {
        $span = $this->reader->dataSpan($project->id);
        if ($span === null) {
            return ['span' => null, 'updates' => [], 'selected' => null];
        }
        $updates = [];
        $selected = null;
        foreach (array_reverse(GoogleUpdates::between($span['first'], $span['last'])) as $update) {
            $before = UpdateImpact::before($update);
            $after = UpdateImpact::after($update);
            $status = $this->status($project, $before, $after, $span);
            $row = [
                'slug' => GoogleUpdates::slug($update['name']),
                'name' => $update['name'],
                'start' => $update['start'],
                'end' => $update['end'],
                'status' => $status,
                'before' => $status === 'complete' ? $this->reader->totals($project->id, $before) : null,
                'after' => $status === 'complete' && $after !== null ? $this->reader->totals($project->id, $after) : null,
            ];
            $updates[] = $row;
            if ($row['slug'] === $slug && $status === 'complete' && $after !== null) {
                $selected = [...$row, 'beforeRange' => $before, 'afterRange' => $after,
                    'pages' => $this->movers($project, PerformanceDimension::Page, $before, $after),
                    'queries' => $this->movers($project, PerformanceDimension::Query, $before, $after)];
            }
        }

        return ['span' => $span, 'updates' => $updates, 'selected' => $selected];
    }

    /**
     * complete, rolling_out (no end yet), waiting (the after window has not
     * passed or is not imported yet) or incomplete (days missing).
     *
     * @param array{first: string, last: string} $span
     */
    private function status(Project $project, DateRange $before, ?DateRange $after, array $span): string
    {
        if ($after === null) {
            return 'rolling_out';
        }
        if ($after->toString() > $span['last']) {
            return 'waiting';
        }
        if ($before->fromString() < $span['first']
            || $this->reader->coveredDays($project->id, $before) < UpdateImpact::WINDOW
            || $this->reader->coveredDays($project->id, $after) < UpdateImpact::WINDOW) {
            return 'incomplete';
        }

        return 'complete';
    }

    /** @return array{winners: list<array{key: string, before: Metrics, after: Metrics, delta: int}>, losers: list<array{key: string, before: Metrics, after: Metrics, delta: int}>} */
    private function movers(Project $project, PerformanceDimension $dimension, DateRange $before, DateRange $after): array
    {
        return UpdateImpact::movers(
            $this->reader->top($project->id, $dimension, $before, self::ROWS),
            $this->reader->top($project->id, $dimension, $after, self::ROWS),
        );
    }
}
