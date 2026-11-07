<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;

final readonly class ProjectOverviewTool implements McpTool
{
    public function __construct(private SearchSources $search, private RankingRepository $rankings, private KeywordRepository $keywords, private CompetitorRepository $competitors, private OpportunityRepository $opportunities) {}
    public function name(): string
    {
        return 'project_overview';
    }
    public function title(): string
    {
        return 'Project overview';
    }
    public function scope(): McpScope
    {
        return McpScope::Projects;
    }
    public function description(): string
    {
        return 'Compact project overview: identity, imported search range/totals, rank check, tracked-keyword/competitor/open-opportunity counts. Each data family also requires its own existing token scope; null can mean scope unavailable. Use this before retrieving long lists.';
    }
    public function inputSchema(): array
    {
        return Present::projectSchema();
    }
    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $token = $context->access->token;
        $search = $token->allows(McpScope::Search) ? $this->search->range($project, 'combined', 28) : null;
        $settings = $token->allows(McpScope::Rankings) ? $this->rankings->settings($project->id) : null;
        return [...Present::project($project), 'available_scopes' => array_map(static fn(McpScope $s): string => $s->value, array_values(array_filter(McpScope::cases(), $token->allows(...)))), 'search' => $search !== null ? ['from' => $search['range']->fromString(), 'to' => $search['range']->toString(), 'engines' => SearchSources::engines($search), 'totals' => Present::metrics($this->search->totals($project, $search, $search['range']))] : null, 'rankings' => $settings !== null ? ['enabled' => $settings->enabled, 'latest' => Present::day($this->rankings->latestDay($project->id, $settings->device))] : null, 'tracked_keywords' => $token->allows(McpScope::Keywords) ? $this->keywords->count($project->id) : null, 'competitors' => $token->allows(McpScope::Competitors) ? count($this->competitors->forProject($project->id)) : null, 'open_opportunities' => $token->allows(McpScope::Opportunities) ? $this->opportunities->count($project->id, 'open') : null, 'notice' => 'Stored data only. Read family-specific tools for dates, evidence and coverage. Null is unavailable, not zero.'];
    }
}
