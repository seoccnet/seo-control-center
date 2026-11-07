<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Pages\PageRepository;
use SeoControlCenter\Modules\Pages\PageService;
use SeoControlCenter\Modules\Pages\PageSummary;

final readonly class PagesListTool implements McpTool
{
    public function __construct(private PageRepository $repository, private PageService $pages) {}
    public function name(): string
    {
        return 'pages_list';
    }
    public function title(): string
    {
        return 'Pages';
    }
    public function description(): string
    {
        return 'Paginated list of every known URL in a project with imported Google and Bing clicks. Use the exact URL with page_details. Stored data only.';
    }
    public function scope(): McpScope
    {
        return McpScope::Pages;
    }
    public function inputSchema(): array
    {
        return Present::projectSchema(['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50], 'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0]]);
    }
    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $range = $this->pages->range($project);
        return ['project_id' => $project->publicId, 'from' => $range->fromString(), 'to' => $range->toString(), 'total' => $this->repository->count($project->id),
            'pages' => array_map(
                static fn(PageSummary $p): array => ['url' => $p->url, 'google' => $p->google !== null ? Present::metrics($p->google) : null, 'bing' => $p->bing !== null ? Present::metrics($p->bing) : null],
                $this->repository->list($project->id, $range, ToolContext::int($arguments, 'limit', 50, 1, 100), ToolContext::int($arguments, 'offset', 0, 0, 5000000))
            )];
    }
}
