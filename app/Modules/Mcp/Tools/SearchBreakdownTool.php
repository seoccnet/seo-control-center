<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\SearchConsole\FilteredRow;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\RowFilter;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Mcp\Server\ToolError;

/**
 * search_queries_list / search_pages_list: top queries or pages from Google,
 * Bing or both. With filters (text, volume, CTR, position), another sort or
 * a period comparison, the list is filtered in the database over every
 * stored Search Console row instead of the bounded top set.
 */
final readonly class SearchBreakdownTool implements McpTool
{
    private const array FILTER_ARGUMENTS = ['contains', 'exclude', 'min_words', 'min_impressions', 'min_clicks', 'min_ctr', 'max_ctr', 'min_position', 'max_position', 'presence', 'sort', 'order', 'compare_previous'];

    public function __construct(
        private SearchSources $sources,
        private PerformanceDimension $dimension,
    ) {}

    public function name(): string
    {
        return $this->dimension === PerformanceDimension::Query ? 'search_queries_list' : 'search_pages_list';
    }

    public function title(): string
    {
        return $this->dimension === PerformanceDimension::Query ? 'Search queries' : 'Search pages';
    }

    public function description(): string
    {
        $what = $this->dimension === PerformanceDimension::Query ? 'queries' : 'pages (URLs)';

        return sprintf(
            'Search %1$s over the last N days of imported data (or from/to), with clicks, impressions, CTR (fraction, 0.02 = 2 %%) and average position. '
            . 'Without filters: top %1$s by clicks; source combined (Google + Bing, default), google or bing. '
            . 'Filters search every stored Search Console row, not only the top rows: contains (any of the terms, case-insensitive; match "all" for every term), exclude, min_words, min_impressions, min_clicks, min_ctr/max_ctr (fractions), min_position/max_position, presence (new = impressions now but none in the previous period, lost = the reverse), sort (clicks, impressions, ctr, position, clicks_change, impressions_change, position_change) with order asc/desc, and compare_previous for the previous period of the same length (sorting by a change or presence compares automatically). matching_totals sums all matching rows (e.g. non-brand totals with exclude [brand]). '
            . 'Examples: low CTR at high impressions = min_impressions 500, max_ctr 0.01, sort impressions; striking distance = min_position 8, max_position 20, sort impressions; question %1$s = contains ["how", "what", "why", "which", "can"]; long tail = min_words 5; biggest losers = sort clicks_change, order asc. '
            . 'Filtered lists use Search Console only (Bing has no comparable daily rows) unless source is bing.',
            $what,
        );
    }

    public function scope(): McpScope
    {
        return McpScope::Search;
    }

    public function inputSchema(): array
    {
        $terms = ['type' => 'array', 'maxItems' => RowFilter::MAX_TERMS, 'items' => ['type' => 'string', 'maxLength' => RowFilter::MAX_TERM_LENGTH]];

        return Present::projectSchema([
            'days' => ['type' => 'integer', 'minimum' => 7, 'maximum' => 486, 'default' => 28],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
            'source' => SearchSources::schema(),
            'from' => ['type' => 'string', 'format' => 'date'],
            'to' => ['type' => 'string', 'format' => 'date'],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000, 'default' => 0],
            'contains' => [...$terms, 'description' => 'Keep rows whose text contains any of these terms (case-insensitive). For pages this matches the URL, e.g. "/blog/".'],
            'match' => ['type' => 'string', 'enum' => ['any', 'all'], 'default' => 'any', 'description' => 'any = at least one contains term, all = every term.'],
            'exclude' => [...$terms, 'description' => 'Drop rows whose text contains any of these terms, e.g. the brand name.'],
            'min_words' => ['type' => 'integer', 'minimum' => 0, 'maximum' => RowFilter::MAX_WORDS, 'description' => 'Only keys with at least this many words (long-tail queries).'],
            'presence' => ['type' => 'string', 'enum' => RowFilter::PRESENCE, 'default' => 'any', 'description' => 'new = no impressions in the previous period, lost = none in the current period.'],
            'min_impressions' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000000],
            'min_clicks' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000000],
            'min_ctr' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'description' => 'Fraction: 0.05 = 5 %.'],
            'max_ctr' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'description' => 'Fraction: 0.01 = 1 %.'],
            'min_position' => ['type' => 'number', 'minimum' => 1, 'maximum' => 1000],
            'max_position' => ['type' => 'number', 'minimum' => 1, 'maximum' => 1000],
            'sort' => ['type' => 'string', 'enum' => RowFilter::SORTS, 'default' => 'clicks'],
            'order' => ['type' => 'string', 'enum' => ['desc', 'asc'], 'description' => 'Default: asc for position, desc otherwise.'],
            'compare_previous' => ['type' => 'boolean', 'default' => false, 'description' => 'Add the previous period of the same length and the change per row.'],
        ]);
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $days = ToolContext::int($arguments, 'days', 28, 7, 486);
        $limit = ToolContext::int($arguments, 'limit', 50, 1, 200);
        $source = SearchSources::source($arguments);
        $scope = $this->sources->range($project, $source, $days);
        if ($scope === null) {
            return ['project_id' => $project->publicId, 'source' => $source, 'rows' => [], 'message' => 'No search data has been imported for this project from this source.'];
        }
        $scope = $this->sources->dated($project, $scope, $arguments);
        $offset = ToolContext::int($arguments, 'offset', 0, 0, 10000);
        $range = ['from' => $scope['range']->fromString(), 'to' => $scope['range']->toString()];

        if (array_intersect(self::FILTER_ARGUMENTS, array_keys($arguments)) !== []) {
            $filter = self::filter($arguments);
            $result = $this->sources->filtered($project, $scope, $source, $this->dimension, $filter, $limit, $offset);
            $previous = $scope['range']->previous();

            return [
                'project_id' => $project->publicId,
                'source' => $result['engine'],
                'range' => $range,
                'previous_range' => $filter->compare ? ['from' => $previous->fromString(), 'to' => $previous->toString()] : null,
                'filters' => self::describe($filter),
                'total_matching' => $result['total'],
                'matching_totals' => Present::metrics($result['totals']),
                'offset' => $offset,
                'next_offset' => $offset + $limit < $result['total'] ? $offset + $limit : null,
                'note' => ($result['exhaustive'] ? 'Filtered over every stored row of this source in the range.' : 'Bing filters cover its top 5,000 rows only.')
                    . ($source === 'combined' && $result['engine'] === 'google' ? ' Filtered lists use Search Console only; Bing is not included.' : '')
                    . ' Search Console omits anonymized queries, so query rows do not add up to the site totals.'
                    . ($filter->compare ? ' Previous-period zeros mean no stored rows, not a proven zero; check coverage before calling a query lost.' : ''),
                'rows' => array_map(static fn(FilteredRow $row): array => self::present($row), $result['rows']),
            ];
        }

        $candidates = $this->sources->candidates($project, $scope, $this->dimension);

        return [
            'project_id' => $project->publicId,
            'source' => $source,
            'engines' => SearchSources::engines($scope),
            'range' => $range,
            'total_in_candidate_set' => count($candidates['rows']),
            'candidate_limit_per_engine' => $candidates['candidate_limit'],
            'candidate_set_truncated' => $candidates['truncated'],
            'offset' => $offset,
            'next_offset' => $offset + $limit < count($candidates['rows']) ? $offset + $limit : null,
            'note' => 'Pagination covers a bounded top candidate set by clicks. If truncated, combined rankings/metrics may exclude contributions outside either engine candidate set; use filters (e.g. min_impressions, sort impressions) to search every stored row. Do not claim exhaustive coverage.',
            'rows' => array_map(Present::row(...), array_slice($candidates['rows'], $offset, $limit)),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function filter(array $arguments): RowFilter
    {
        $terms = static function (string $name) use ($arguments): array {
            $value = $arguments[$name] ?? [];
            if (is_string($value)) {
                $value = [$value];
            }
            if (!is_array($value) || !array_is_list($value) || array_filter($value, static fn($t): bool => !is_string($t)) !== []) {
                throw new ToolError($name . ' must be a list of strings.');
            }

            return $value;
        };
        $number = static function (string $name) use ($arguments): ?float {
            $value = $arguments[$name] ?? null;
            if ($value !== null && !is_int($value) && !is_float($value)) {
                throw new ToolError($name . ' must be a number.');
            }

            return $value === null ? null : (float) $value;
        };
        $sort = ToolContext::choice($arguments, 'sort', 'clicks', RowFilter::SORTS);
        $order = ToolContext::choice($arguments, 'order', $sort === 'position' ? 'asc' : 'desc', ['asc', 'desc']);
        $compare = $arguments['compare_previous'] ?? false;
        if (!is_bool($compare)) {
            throw new ToolError('compare_previous must be true or false.');
        }
        try {
            return new RowFilter(
                $terms('contains'),
                ToolContext::choice($arguments, 'match', 'any', ['any', 'all']) === 'all',
                $terms('exclude'),
                ToolContext::int($arguments, 'min_impressions', 0, 0, 100000000),
                ToolContext::int($arguments, 'min_clicks', 0, 0, 100000000),
                $number('min_ctr'),
                $number('max_ctr'),
                $number('min_position'),
                $number('max_position'),
                $sort,
                $order === 'desc',
                $compare,
                ToolContext::int($arguments, 'min_words', 0, 0, RowFilter::MAX_WORDS),
                ToolContext::choice($arguments, 'presence', 'any', RowFilter::PRESENCE),
            );
        } catch (\InvalidArgumentException $e) {
            throw new ToolError($e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(RowFilter $filter): array
    {
        return array_filter([
            'contains' => $filter->contains,
            'match' => $filter->contains !== [] ? ($filter->containsAll ? 'all' : 'any') : null,
            'exclude' => $filter->exclude,
            'min_words' => $filter->minWords ?: null,
            'presence' => $filter->presence !== 'any' ? $filter->presence : null,
            'min_impressions' => $filter->minImpressions ?: null,
            'min_clicks' => $filter->minClicks ?: null,
            'min_ctr' => $filter->minCtr,
            'max_ctr' => $filter->maxCtr,
            'min_position' => $filter->minPosition,
            'max_position' => $filter->maxPosition,
            'sort' => $filter->sort,
            'order' => $filter->descending ? 'desc' : 'asc',
            'compare_previous' => $filter->compare ?: null,
        ], static fn($v): bool => $v !== null && $v !== []);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(FilteredRow $row): array
    {
        $result = ['key' => $row->key, ...Present::metrics($row->current)];
        if ($row->previous !== null) {
            $p = $row->previous;
            $result['previous'] = Present::metrics($p);
            $result['change'] = [
                'clicks' => $row->current->clicks - $p->clicks,
                'impressions' => $row->current->impressions - $p->impressions,
                'ctr_points' => round(($row->current->ctr() - $p->ctr()) * 100, 2),
                'position' => $row->current->impressions > 0 && $p->impressions > 0 ? round($row->current->position - $p->position, 1) : null,
            ];
        }

        return $result;
    }
}
