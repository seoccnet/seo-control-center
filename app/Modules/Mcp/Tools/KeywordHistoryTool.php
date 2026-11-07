<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Rankings\RankCheck;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Mcp\Server\ToolError;

final readonly class KeywordHistoryTool implements McpTool
{
    public function __construct(
        private KeywordRepository $keywords,
        private RankingRepository $rankings,
        private Clock $clock,
    ) {}

    public function name(): string
    {
        return 'keyword_history';
    }

    public function title(): string
    {
        return 'Keyword ranking history';
    }

    public function description(): string
    {
        return 'All rank checks of one tracked keyword over the last N days: position (null = not in the top 100), ranking URL, SERP features and the top 3 domains.';
    }

    public function scope(): McpScope
    {
        return McpScope::Rankings;
    }

    public function inputSchema(): array
    {
        return [...Present::projectSchema([
            'keyword_id' => ['type' => 'string', 'description' => 'keyword_id from keywords_list or rankings_summary.'],
            'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 90],
        ]), 'required' => ['project_id', 'keyword_id']];
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $keywordId = $arguments['keyword_id'] ?? null;
        $keyword = is_string($keywordId) ? $this->keywords->find($project->id, $project->workspaceId, $keywordId) : null;
        if ($keyword === null) {
            throw new ToolError('Keyword not found in this project.');
        }
        $days = ToolContext::int($arguments, 'days', 90, 1, 365);
        $settings = $this->rankings->settings($project->id);
        $since = $this->clock->now()->modify(sprintf('-%d days', $days))->format('Y-m-d');

        return [
            'project_id' => $project->publicId,
            'keyword_id' => $keyword->publicId,
            'keyword' => $keyword->keyword,
            'market' => $keyword->locale->label(),
            'device' => $settings->device->value,
            'search_volume' => $keyword->metrics?->searchVolume,
            'checks' => array_map(static fn(RankCheck $c): array => [
                'date' => $c->date,
                'position' => $c->position,
                'url' => $c->url,
                'serp_features' => $c->features,
                'top_domains' => array_map(static fn(array $r): string => $r['domain'], array_slice($c->top, 0, 3)),
            ], $this->rankings->history($project->id, $keyword->publicId, $settings->device, $since)),
        ];
    }
}
