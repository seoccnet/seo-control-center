<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\ArticleIdeas\TopicPlan;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\ArticleIdeas\TopicPlanService;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\Paging;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;

/**
 * topic_plans (ADR-041, G2): pillar articles with their supporting articles,
 * searches and pages, the internal link map and the competitor backlinks.
 */
final readonly class TopicPlansTool implements McpTool
{
    public function __construct(private TopicPlanService $plans) {}

    public function name(): string
    {
        return 'topic_plans';
    }

    public function title(): string
    {
        return 'Topic plans';
    }

    public function description(): string
    {
        return 'Topic plans of a project, newest first. Each has a pillar (an article idea: idea_key, main_keyword) and members: type idea (a supporting article idea; volume = monthly searches), search (a Search Console query no idea covers; volume = impressions) or page (your page on the subject; volume = impressions), each with kept true/false as the user decided. links: internal (from/to member keys or "pillar", with anchor) and pages (links from your crawled pages to a planned article: source_url, to, anchor, paragraph), plus status and built_at; null until built. backlinks: status, checked_at, pages (url, referring_domains, backlinks, rank) of the pillar\'s top competitor pages and referring_domains_to_match; null until bought in the app. Read-only: plans are made and changed in the app (Research → Article ideas → Plan a topic). Paged with offset and limit (10 by default, at most 50); total counts all plans and next_offset is null on the last page.';
    }

    public function scope(): McpScope
    {
        return McpScope::Keywords;
    }

    public function inputSchema(): array
    {
        return Present::projectSchema(Paging::schema(10, 50));
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $page = Paging::slice(array_values($this->plans->plans($project)), $arguments, 10, 50);

        return [
            'project_id' => $project->publicId,
            'offset' => $page['offset'], 'limit' => $page['limit'], 'total' => $page['total'], 'next_offset' => $page['next_offset'],
            'plans' => array_map(static fn(TopicPlan $plan): array => $plan->present(), $page['rows']),
        ];
    }
}
