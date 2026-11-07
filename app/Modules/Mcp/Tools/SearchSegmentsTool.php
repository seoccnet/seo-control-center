<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\Paging;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Mcp\Server\ToolError;

/**
 * search_segments: Search Console totals per country or device, optionally
 * compared with the previous period. These breakdowns are site-wide: the
 * import has no page-by-country or query-by-country rows.
 */
final readonly class SearchSegmentsTool implements McpTool
{
    private const int MAX_VALUES = 300;

    public function __construct(private SearchPerformanceReader $google) {}

    public function name(): string
    {
        return 'search_segments';
    }

    public function title(): string
    {
        return 'Countries and devices';
    }

    public function description(): string
    {
        return 'Google Search Console clicks, impressions, CTR (fraction) and average position per country or per device (desktop, mobile, tablet) for the last N days or from/to, with each segment\'s share of the total and optionally the previous period. '
            . 'Countries are lowercase ISO 3166-1 alpha-3 codes (usa, gbr, deu, can, aus, ind, fra, esp, ita, nld, bra, mex). values limits the result to some segments, e.g. ["usa"]. '
            . 'Segments are site-wide totals: there is no page-by-country or query-by-country breakdown, so do not present country numbers for a single page or query. Bing is not included. Paged with offset and limit (25 by default, at most 100); total counts all rows and next_offset is null on the last page.';
    }

    public function scope(): McpScope
    {
        return McpScope::Search;
    }

    public function inputSchema(): array
    {
        return Present::projectSchema([
            'dimension' => ['type' => 'string', 'enum' => ['country', 'device'], 'default' => 'country'],
            'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 486, 'default' => 28],
            'from' => ['type' => 'string', 'format' => 'date'],
            'to' => ['type' => 'string', 'format' => 'date'],
            'values' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string', 'maxLength' => 16], 'description' => 'Only these countries (alpha-3) or devices.'],
            'compare_previous' => ['type' => 'boolean', 'default' => false],
            ...Paging::schema(25, 100),
        ]);
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $dimension = ToolContext::choice($arguments, 'dimension', 'country', ['country', 'device']) === 'device' ? PerformanceDimension::Device : PerformanceDimension::Country;
        $days = ToolContext::int($arguments, 'days', 28, 1, 486);
        $compare = $arguments['compare_previous'] ?? false;
        if (!is_bool($compare)) {
            throw new ToolError('compare_previous must be true or false.');
        }
        $values = $arguments['values'] ?? [];
        if (!is_array($values) || !array_is_list($values) || array_filter($values, static fn($v): bool => !is_string($v)) !== []) {
            throw new ToolError('values must be a list of strings.');
        }
        $values = array_values(array_unique(array_map(static fn(string $v): string => strtolower(trim($v)), $values)));

        $span = $this->google->dataSpan($project->id);
        if ($span === null) {
            return ['project_id' => $project->publicId, 'dimension' => $dimension->value, 'rows' => [], 'message' => 'No Search Console data has been imported for this project.'];
        }
        $range = ReadInput::range($arguments, DateRange::endingOn($span['last'], $days), 486);
        $previous = $range->previous();
        $current = $this->google->top($project->id, $dimension, $range, self::MAX_VALUES);
        $before = $compare ? array_column(array_map(static fn(DimensionRow $r): array => ['k' => $r->key, 'm' => $r->metrics], $this->google->top($project->id, $dimension, $previous, self::MAX_VALUES)), 'm', 'k') : [];
        $total = Metrics::sum(array_map(static fn(DimensionRow $r): Metrics => $r->metrics, $current));
        $rows = array_values(array_filter($current, static fn(DimensionRow $r): bool => $values === [] || in_array(strtolower($r->key), $values, true)));
        $page = Paging::slice($rows, $arguments, 25, 100);
        $missing = array_values(array_diff($values, array_map(static fn(DimensionRow $r): string => strtolower($r->key), $rows)));

        return [
            'project_id' => $project->publicId,
            'source' => 'google',
            'dimension' => $dimension->value,
            'range' => ['from' => $range->fromString(), 'to' => $range->toString()],
            'previous_range' => $compare ? ['from' => $previous->fromString(), 'to' => $previous->toString()] : null,
            'data_ends' => $span['last'],
            'covered_days' => $this->google->coveredDays($project->id, $range),
            'expected_days' => $range->days(),
            'segment_total' => Present::metrics($total),
            'not_found' => $missing,
            'offset' => $page['offset'], 'limit' => $page['limit'], 'total' => $page['total'], 'next_offset' => $page['next_offset'],
            'note' => 'Site-wide Search Console segments. Shares are of the segment total; Search Console dates are Pacific Time and the last 2-3 days may be incomplete. Covered days below expected days means a partial period.',
            'rows' => array_map(static function (DimensionRow $row) use ($total, $compare, $before): array {
                $result = ['key' => $row->key, ...Present::metrics($row->metrics), 'click_share' => $total->clicks > 0 ? round($row->metrics->clicks / $total->clicks, 4) : null, 'impression_share' => $total->impressions > 0 ? round($row->metrics->impressions / $total->impressions, 4) : null];
                if ($compare) {
                    $p = $before[$row->key] ?? null;
                    $result['previous'] = $p !== null ? Present::metrics($p) : null;
                    $result['change'] = $p !== null ? ['clicks' => $row->metrics->clicks - $p->clicks, 'impressions' => $row->metrics->impressions - $p->impressions, 'ctr_points' => round(($row->metrics->ctr() - $p->ctr()) * 100, 2), 'position' => $row->metrics->impressions > 0 && $p->impressions > 0 ? round($row->metrics->position - $p->position, 1) : null] : null;
                }

                return $result;
            }, $page['rows']),
        ];
    }
}
