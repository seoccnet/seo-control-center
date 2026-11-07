<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;

final readonly class SearchPerformanceSummaryTool implements McpTool
{
    public function __construct(private SearchSources $sources) {}

    public function name(): string
    {
        return 'search_performance_summary';
    }

    public function title(): string
    {
        return 'Search performance summary';
    }

    public function description(): string
    {
        return 'Search totals (clicks, impressions, CTR, average position) for the last N days of imported data, compared with the N days before, plus the top 10 queries and pages. source: combined (Google Search Console + Bing Webmaster Tools, default; the position is Google\'s), google or bing. Dates are Pacific Time.';
    }

    public function scope(): McpScope
    {
        return McpScope::Search;
    }

    public function inputSchema(): array
    {
        return Present::projectSchema([
            'days' => ['type' => 'integer', 'minimum' => 7, 'maximum' => 486, 'default' => 28, 'description' => 'Window length in days.'],
            'source' => SearchSources::schema(),
            'from' => ['type' => 'string', 'format' => 'date'],
            'to' => ['type' => 'string', 'format' => 'date'],
            'include_daily' => ['type' => 'boolean', 'default' => false],
        ]);
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $days = ToolContext::int($arguments, 'days', 28, 7, 486);
        $source = SearchSources::source($arguments);
        $scope = $this->sources->range($project, $source, $days);
        if ($scope === null) {
            return ['project_id' => $project->publicId, 'source' => $source, 'data' => false, 'message' => 'No search data has been imported for this project from this source.'];
        }
        $scope = $this->sources->dated($project, $scope, $arguments);
        $range = $scope['range'];
        if (SearchSources::engines($scope) === []) {
            return ['project_id' => $project->publicId, 'source' => $source, 'data' => false, 'range' => ['from' => $range->fromString(), 'to' => $range->toString()], 'totals' => null, 'message' => 'The requested dates do not overlap stored search data. Missing data is not zero.'];
        }
        $previous = $range->previous();

        return [
            'project_id' => $project->publicId,
            'source' => $source,
            'engines' => SearchSources::engines($scope),
            'data' => true,
            'range' => ['from' => $range->fromString(), 'to' => $range->toString()],
            'totals' => Present::metrics($this->sources->totals($project, $scope, $range)),
            'previous_range' => ['from' => $previous->fromString(), 'to' => $previous->toString()],
            'previous_totals' => $scope['hasPrevious'] ? Present::metrics($this->sources->totals($project, $scope, $previous)) : null,
            'daily' => ($arguments['include_daily'] ?? false) === true ? $this->sources->daily($project, $scope) : null,
            'top_queries' => array_map(Present::row(...), $this->sources->top($project, $scope, PerformanceDimension::Query, 10)),
            'top_pages' => array_map(Present::row(...), $this->sources->top($project, $scope, PerformanceDimension::Page, 10)),
            'notes' => 'Totals sum stored observations; missing dates and partial imports are not proven zeros. ' . ($scope['bing'] ? 'Bing reports no site-wide position per day and its queries and pages per week; positions of combined rows are weighted by impressions.' : ''),
        ];
    }
}
