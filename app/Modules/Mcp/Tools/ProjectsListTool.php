<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\Paging;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;

final class ProjectsListTool implements McpTool
{
    public function name(): string
    {
        return 'projects_list';
    }

    public function title(): string
    {
        return 'List projects';
    }

    public function description(): string
    {
        return 'Lists the SEO projects this token may read (ID, name, domain, time zone). Use the project_id with the other tools. Paged with offset and limit (50 by default, at most 200); total counts all projects and next_offset is null on the last page.';
    }

    public function scope(): McpScope
    {
        return McpScope::Projects;
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => Paging::schema(50, 200), 'additionalProperties' => false];
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $page = Paging::slice($context->projects(), $arguments, 50, 200);

        return ['offset' => $page['offset'], 'limit' => $page['limit'], 'total' => $page['total'], 'next_offset' => $page['next_offset'], 'projects' => array_map(Present::project(...), $page['rows'])];
    }
}
