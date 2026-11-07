<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Competitors\KeywordComparison;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Mcp\Server\ToolError;

final readonly class CompetitorCompareTool implements McpTool
{
    public function __construct(
        private CompetitorRepository $competitors,
        private RankingRepository $rankings,
    ) {}

    public function name(): string
    {
        return 'competitor_compare';
    }

    public function title(): string
    {
        return 'Compare with a competitor';
    }

    public function description(): string
    {
        return 'Keyword-by-keyword positions of the project and one competitor on the latest rank check (null = not in the top 100). Filter "gaps" = competitor on page one and at least 10 places ahead; "behind" = competitor ahead; "ahead" = project ahead.';
    }

    public function scope(): McpScope
    {
        return McpScope::Competitors;
    }

    public function inputSchema(): array
    {
        return [...Present::projectSchema([
            'competitor_id' => ['type' => 'string', 'description' => 'competitor_id from competitors_list.'],
            'filter' => ['type' => 'string', 'enum' => ['all', 'gaps', 'behind', 'ahead'], 'default' => 'all'],
        ]), 'required' => ['project_id', 'competitor_id']];
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $competitorId = $arguments['competitor_id'] ?? null;
        $competitor = is_string($competitorId) ? $this->competitors->find($project->id, $competitorId) : null;
        if ($competitor === null) {
            throw new ToolError('Competitor not found in this project.');
        }
        $filter = ToolContext::choice($arguments, 'filter', 'all', ['all', 'gaps', 'behind', 'ahead']);
        $settings = $this->rankings->settings($project->id);
        $latest = $this->rankings->latestDay($project->id, $settings->device);
        $rows = $latest !== null ? $this->competitors->compare($project->id, $project->workspaceId, $competitor->id, $settings->device, $latest->date) : [];
        $rows = array_values(array_filter($rows, static fn(KeywordComparison $c): bool => match ($filter) {
            'gaps' => $c->isGap(),
            'behind' => $c->lead() < 0,
            'ahead' => $c->lead() > 0,
            default => true,
        }));

        return [
            'project_id' => $project->publicId,
            'competitor' => $competitor->domain,
            'check_date' => $latest?->date,
            'filter' => $filter,
            'keywords' => array_map(static fn(KeywordComparison $c): array => [
                'keyword_id' => $c->keywordPublicId,
                'keyword' => $c->keyword,
                'search_volume' => $c->searchVolume,
                'own_position' => $c->own,
                'competitor_position' => $c->theirs,
                'lead' => $c->lead(),
                'gap' => $c->isGap(),
                'competitor_url' => $c->theirUrl,
            ], $rows),
        ];
    }
}
