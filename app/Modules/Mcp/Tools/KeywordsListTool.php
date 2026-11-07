<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Keywords\ProjectKeyword;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;

final readonly class KeywordsListTool implements McpTool
{
    public function __construct(private KeywordRepository $keywords) {}

    public function name(): string
    {
        return 'keywords_list';
    }

    public function title(): string
    {
        return 'Tracked keywords';
    }

    public function description(): string
    {
        return 'Keywords tracked by the project with market (country, language) and metrics from DataForSEO (Google Ads search volume, CPC in USD, competition 0-100), sorted by search volume.';
    }

    public function scope(): McpScope
    {
        return McpScope::Keywords;
    }

    public function inputSchema(): array
    {
        return Present::projectSchema([
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
        ]);
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $limit = ToolContext::int($arguments, 'limit', 100, 1, 500);
        $offset = ToolContext::int($arguments, 'offset', 0, 0, PHP_INT_MAX);

        return [
            'project_id' => $project->publicId,
            'total' => $this->keywords->count($project->id),
            'keywords' => array_map(static fn(ProjectKeyword $k): array => [
                'keyword_id' => $k->publicId,
                'keyword' => $k->keyword,
                'country_code' => $k->locale->locationCode,
                'market' => $k->locale->label(),
                'search_volume' => $k->metrics?->searchVolume,
                'cpc_usd' => $k->metrics?->cpc,
                'competition' => $k->metrics?->competition,
                'monthly_searches' => $k->metrics->monthly ?? [],
                'metrics_updated_at' => $k->metricsFetchedAt,
            ], $this->keywords->page($project->id, $project->workspaceId, $limit, $offset)),
        ];
    }
}
