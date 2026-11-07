<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\SerpHistory\SerpEvent;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Mcp\Server\ToolError;
use SeoControlCenter\Modules\SerpHistory\SerpHistoryController;
use SeoControlCenter\Modules\SerpHistory\SerpHistoryService;

/**
 * serp_changes (ADR-039, E1): results-page change history of the tracked
 * keywords, derived from stored rank checks. Reads only.
 */
final readonly class SerpChangesTool implements McpTool
{
    public function __construct(private SerpHistoryService $history) {}

    public function name(): string
    {
        return 'serp_changes';
    }

    public function title(): string
    {
        return 'Results-page changes';
    }

    public function description(): string
    {
        return 'What changed on the results pages of tracked keywords between two rank checks on the same device (at most 14 days apart): features appearing or disappearing (AI Overview, featured snippet, local pack…), tracked competitors entering or leaving the top 10 (and whether above the project), new competitor or own URLs in the top 10, the project entering, leaving or moving 5+ places, and the dominant page type shifting. Plus 30-day counts and per-competitor movement. Filter by kind or group (features, competitors, yours), keyword text, domain/URL/feature text and dates. Stored data only.';
    }

    public function scope(): McpScope
    {
        return McpScope::Rankings;
    }

    public function inputSchema(): array
    {
        return Present::projectSchema([
            'kind' => ['type' => 'string', 'description' => 'One of: features, competitors, yours, feature_added, feature_removed, competitor_entered, competitor_left, new_url, own_entered, own_left, own_moved, format_shift.'],
            'q' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Keyword contains.'],
            'subject' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Domain, URL or feature contains.'],
            'from' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            'to' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
        ]);
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        foreach (['kind', 'q', 'subject', 'from', 'to'] as $key) {
            if (isset($arguments[$key]) && !is_string($arguments[$key])) {
                throw new ToolError($key . ' must be text.');
            }
        }
        $filters = SerpHistoryController::filters($arguments);
        $offset = is_int($arguments['offset'] ?? null) ? max(0, $arguments['offset']) : 0;
        $data = $this->history->overview($project, $filters, $offset);

        return [
            'project_id' => $project->publicId,
            'device' => $data['device'],
            'total' => $data['total'],
            'offset' => $offset,
            'next_offset' => $offset + SerpHistoryService::PER_PAGE < $data['total'] ? $offset + SerpHistoryService::PER_PAGE : null,
            'counts_30_days' => $data['counts'],
            'competitors_30_days' => $data['competitors'],
            'changes' => array_map(static fn(SerpEvent $e): array => $e->present(), $data['events']),
            'notice' => 'Each change compares two stored rank checks of the same keyword and device; a missing check is a gap, not a change. Page types come from result addresses. Changes describe what the results page showed, not their cause.',
        ];
    }
}
