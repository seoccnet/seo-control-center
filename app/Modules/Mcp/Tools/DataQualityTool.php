<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\DataQuality\DataQualityService;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;

/**
 * data_quality: the Data quality page as data (DataQualityService). Lets an
 * AI client check its evidence before drawing conclusions. Reads only.
 */
final readonly class DataQualityTool implements McpTool
{
    public function __construct(private DataQualityService $quality) {}

    public function name(): string
    {
        return 'data_quality';
    }

    public function title(): string
    {
        return 'Data quality';
    }

    public function description(): string
    {
        return 'Check which stored datasets of a project are available, stale, partial (sampled) or missing right now, with their last date and how to interpret each status; which days are missing from the Search Console and GA4 imports (missing days are not zeros); GA4 sampling, key-event availability and attribution limits; and source combinations that are not directly comparable. Call it before drawing conclusions, comparing periods or claiming something is zero. Stored data only; no import or provider request.';
    }

    public function scope(): McpScope
    {
        return McpScope::Projects;
    }

    public function inputSchema(): array
    {
        return Present::projectSchema(['group' => ['type' => 'string', 'enum' => ['', ...DataQualityService::GROUPS], 'description' => 'Optional: only one group of datasets. Without a group, import completeness and compatibility checks are included.']]);
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $group = ToolContext::choice($arguments, 'group', '', ['', ...DataQualityService::GROUPS]);
        $notice = 'Statuses describe stored snapshots, not live provider availability. Not connected and never imported both read as missing.';
        if ($group !== '') {
            $datasets = $this->quality->datasets($project, $group);

            return ['project_id' => $project->publicId, 'summary' => DataQualityService::summary($datasets), 'datasets' => $datasets, 'interpretation' => DataQualityService::RULES, 'notice' => $notice];
        }

        return ['project_id' => $project->publicId, ...$this->quality->inspect($project), 'notice' => $notice];
    }
}
