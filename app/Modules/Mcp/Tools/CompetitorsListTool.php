<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\Paging;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;

final readonly class CompetitorsListTool implements McpTool
{
    public function __construct(
        private CompetitorRepository $competitors,
        private RankingRepository $rankings,
    ) {}

    public function name(): string
    {
        return 'competitors_list';
    }

    public function title(): string
    {
        return 'Competitors';
    }

    public function description(): string
    {
        return 'The project\'s competitors with their visibility, average position and top 3/10 counts on the latest rank check, next to the project\'s own values. Only the project\'s tracked keywords are compared. Paged with offset and limit (25 by default, at most 100); total counts all competitors and next_offset is null on the last page.';
    }

    public function scope(): McpScope
    {
        return McpScope::Competitors;
    }

    public function inputSchema(): array
    {
        return Present::projectSchema(Paging::schema(25, 100));
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $settings = $this->rankings->settings($project->id);
        $latest = $this->rankings->latestDay($project->id, $settings->device);
        $days = $latest !== null ? $this->competitors->days($project->id, $settings->device, $latest->date) : [];
        $page = Paging::slice(array_values($this->competitors->forProject($project->id)), $arguments, 25, 100);

        return [
            'project_id' => $project->publicId,
            'check_date' => $latest?->date,
            'own' => ['domain' => $project->primaryDomain, ...(Present::day($latest) ?? [])],
            'offset' => $page['offset'], 'limit' => $page['limit'], 'total' => $page['total'], 'next_offset' => $page['next_offset'],
            'competitors' => array_map(static fn(Competitor $c): array => [
                'competitor_id' => $c->publicId,
                'domain' => $c->domain,
                'source' => $c->source,
                'latest' => Present::day($days[$c->id] ?? null),
            ], $page['rows']),
        ];
    }
}
