<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Opportunities\OpportunityOutcome;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\Paging;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Opportunities\OpportunityService;

/**
 * opportunity_outcomes (ADR-039, E2): predicted vs observed results of
 * completed opportunities, with whether the change was verified and which
 * other changes overlapped the measurement window.
 */
final readonly class OutcomesTool implements McpTool
{
    public function __construct(private OpportunityService $service) {}

    public function name(): string
    {
        return 'opportunity_outcomes';
    }

    public function title(): string
    {
        return 'Opportunity outcomes';
    }

    public function description(): string
    {
        return 'Predicted vs observed clicks for completed opportunities at day 28 and day 56 (latest 50 checkpoints). implementation is unverified, verified (page monitoring saw the page change within 14 days of completion), confirmed or not_implemented. overlaps lists other change notes, completions for the same search or page and later page changes inside the window, so the result may not be this change alone. Paged with offset and limit (25 by default, at most 100); total counts all outcomes and next_offset is null on the last page.';
    }

    public function scope(): McpScope
    {
        return McpScope::Opportunities;
    }

    public function inputSchema(): array
    {
        return Present::projectSchema(Paging::schema(25, 100));
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $page = Paging::slice(array_values($this->service->outcomes($project)), $arguments, 25, 100);

        return [
            'project_id' => $project->publicId,
            'offset' => $page['offset'], 'limit' => $page['limit'], 'total' => $page['total'], 'next_offset' => $page['next_offset'],
            'outcomes' => array_map(static fn(OpportunityOutcome $row): array => $row->present(), $page['rows']),
            'notice' => 'Correlation, not attribution. Search Console days use Pacific time; click changes are normalized to 30 days. Not-implemented outcomes leave the estimate calibration, which prefers outcomes without overlaps once there are five.',
        ];
    }
}
