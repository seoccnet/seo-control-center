<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Research\ResearchDomain;
use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Research\ResearchRepository;

final readonly class KeywordResearchTool implements McpTool
{
    private const array KINDS = ['ranked', 'gap', 'ideas', 'competitors', 'overview'];

    public function __construct(
        private ResearchRepository $research,
        private CompetitorRepository $competitors,
        private ?\SeoControlCenter\Domain\Opportunities\BusinessContextRepository $business = null,
    ) {}

    public function name(): string
    {
        return 'keyword_research';
    }

    public function title(): string
    {
        return 'Keyword research';
    }

    public function description(): string
    {
        return 'Stored keyword research of the project from the DataForSEO keyword database: `ranked` (keywords the domain ranks for, with position, volume, difficulty, intent and estimated traffic), `gap` (keywords a competitor ranks for and the project does not; pass competitor_id from competitors_list), `ideas` (keyword ideas for the seed keywords last used), `competitors` (domains sharing the most keywords) and `overview` (keywords, top 3/10 and estimated traffic per domain). Each keyword carries `relevance` (relevant, unsure, not_relevant or unchecked) with the reason, from the business profile and team ratings; filter with `relevance`. Returns the last stored lookup and when it was fetched; it starts no paid lookup.';
    }

    public function scope(): McpScope
    {
        return McpScope::Keywords;
    }

    public function inputSchema(): array
    {
        return Present::projectSchema([
            'kind' => ['type' => 'string', 'enum' => self::KINDS, 'default' => 'ranked'],
            'competitor_id' => ['type' => 'string', 'description' => 'For kind=gap: the competitor.'],
            'min_volume' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
            'relevance' => ['type' => 'string', 'enum' => ['all', 'hide_not_relevant', 'relevant', 'unsure', 'not_relevant'], 'default' => 'all', 'description' => 'Filter by fit with the business profile.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
        ]);
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $kind = ToolContext::choice($arguments, 'kind', 'ranked', self::KINDS);
        $subject = '';
        if ($kind === 'gap') {
            $competitorId = is_string($arguments['competitor_id'] ?? null) ? $arguments['competitor_id'] : '';
            $competitor = $this->competitors->find($project->id, $competitorId) ?? throw new \InvalidArgumentException('competitor_id is required for kind=gap and must be one of the project\'s competitors.');
            $subject = $competitor->publicId;
        }
        $result = $this->research->find($project->id, $kind, $subject);
        if ($result === null || $result->fetchedAt === null) {
            return ['project_id' => $project->publicId, 'kind' => $kind, 'fetched_at' => null, 'note' => 'Not looked up yet. Lookups are started in the app (Keywords → Keyword research).'];
        }
        $limit = ToolContext::int($arguments, 'limit', 100, 1, 500);
        $offset = ToolContext::int($arguments, 'offset', 0, 0, 100000);
        $minVolume = ToolContext::int($arguments, 'min_volume', 0, 0, PHP_INT_MAX);
        $locale = $result->locale();
        $base = ['project_id' => $project->publicId, 'kind' => $kind, 'fetched_at' => $result->fetchedAt, 'market' => $locale->label(), 'offset' => $offset, 'note' => 'Pagination covers only the stored provider sample, not every keyword on the web.'];
        if ($kind === 'competitors' || $kind === 'overview') {
            return [...$base, 'total' => count($result->domains()), 'next_offset' => $offset + $limit < count($result->domains()) ? $offset + $limit : null, 'domains' => array_map(static fn(ResearchDomain $d): array => [
                'domain' => $d->domain,
                'keywords' => $d->keywords,
                'top3' => $d->top3,
                'top10' => $d->top10,
                'estimated_traffic' => $d->traffic,
                'shared_keywords' => $d->sharedKeywords,
                'average_position' => $d->averagePosition,
            ], array_slice($result->domains(), $offset, $limit))];
        }
        $filter = ToolContext::choice($arguments, 'relevance', 'all', ['all', 'hide_not_relevant', 'relevant', 'unsure', 'not_relevant']);
        $relevance = new \SeoControlCenter\Domain\Opportunities\Relevance($this->business?->find($project->id), $this->business?->ratings($project->id) ?? []);
        $keywords = array_values(array_filter($result->keywords(), static function (ResearchKeyword $k) use ($minVolume, $filter, $relevance): bool {
            $verdict = $relevance->of($k->keyword)['verdict'];

            return ($k->searchVolume ?? 0) >= $minVolume && match ($filter) {
                'all' => true,
                'hide_not_relevant' => $verdict !== \SeoControlCenter\Domain\Opportunities\Relevance::NOT_RELEVANT,
                default => $verdict === $filter,
            };
        }));

        return [...$base, 'total' => count($keywords), 'next_offset' => $offset + $limit < count($keywords) ? $offset + $limit : null, 'seeds' => $kind === 'ideas' ? ($result->params['seeds'] ?? []) : null, 'keywords' => array_map(static fn(ResearchKeyword $k): array => [
            'keyword' => $k->keyword,
            'search_volume' => $k->searchVolume,
            'cpc' => $k->cpc,
            'difficulty' => $k->difficulty,
            'intent' => $k->intent,
            'position' => $k->position,
            'url' => $k->url,
            'estimated_traffic' => $k->traffic,
            'relevance' => $relevance->of($k->keyword),
        ], array_slice($keywords, $offset, $limit))];
    }
}
