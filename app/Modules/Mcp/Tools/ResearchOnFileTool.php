<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Research\ResearchOnFile;

/**
 * research_on_file: the paid lookups and "Why they outrank you" analyses a
 * project already has (ADR-038, D3), so an AI client reuses stored data
 * instead of suggesting the same paid research again. Reads only.
 */
final readonly class ResearchOnFileTool implements McpTool
{
    public function __construct(private ResearchOnFile $onFile) {}

    public function name(): string
    {
        return 'research_on_file';
    }

    public function title(): string
    {
        return 'Research on file';
    }

    public function description(): string
    {
        return 'List the paid research a project already has: keyword research, gap, competitor, AI visibility and backlink lookups with their market, inputs (seeds, prompts, domains), date and whether they are still current (fresh for 30 days), the topics analyzed with "Why they outrank you", and how many tracked searches have a stored results page. Call it before recommending a new paid lookup; read the stored results with keyword_research, backlinks or ai_visibility. Stored data only; nothing is bought.';
    }

    public function scope(): McpScope
    {
        return McpScope::Keywords;
    }

    public function inputSchema(): array
    {
        return Present::projectSchema();
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);

        return ['project_id' => $project->publicId, ...$this->onFile->list($project), 'notice' => 'Dates are when each lookup finished (UTC). A current lookup with the same inputs is refused in the app unless the user confirms a new paid run.'];
    }
}
