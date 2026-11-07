<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Rankings\RankedKeyword;
use SeoControlCenter\Domain\Rankings\RankingMove;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;

final readonly class RankingsSummaryTool implements McpTool
{
    private const int COMPARE_DAYS = 7;

    public function __construct(private RankingRepository $rankings) {}

    public function name(): string
    {
        return 'rankings_summary';
    }

    public function title(): string
    {
        return 'Rankings summary';
    }

    public function description(): string
    {
        return 'Latest Google rank check of the project: visibility (volume-weighted share of position-1 clicks), average position, top 3/10, the check about 7 days earlier, the biggest winners and losers, and each keyword\'s position (null = not in the top 100).';
    }

    public function scope(): McpScope
    {
        return McpScope::Rankings;
    }

    public function inputSchema(): array
    {
        return Present::projectSchema(['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 100, 'description' => 'Keywords to include, best positions first.'], 'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0]]);
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $limit = ToolContext::int($arguments, 'limit', 100, 1, 1000);
        $offset = ToolContext::int($arguments, 'offset', 0, 0, 100000);
        $settings = $this->rankings->settings($project->id);
        $latest = $this->rankings->latestDay($project->id, $settings->device);
        if ($latest === null) {
            return ['project_id' => $project->publicId, 'tracking_enabled' => $settings->enabled, 'latest' => null, 'message' => 'No rank check yet.'];
        }
        $compare = $this->rankings->dayOnOrBefore($project->id, $settings->device, (new \DateTimeImmutable($latest->date))->modify(sprintf('-%d days', self::COMPARE_DAYS))->format('Y-m-d'));
        $moves = $compare !== null ? $this->rankings->moves($project->id, $settings->device, $compare->date, $latest->date) : [];
        usort($moves, static fn(RankingMove $a, RankingMove $b): int => $b->change() <=> $a->change());
        $move = static fn(RankingMove $m): array => ['keyword' => $m->keyword, 'from' => $m->from, 'to' => $m->to, 'change' => $m->change()];

        return [
            'project_id' => $project->publicId,
            'total' => $this->rankings->countKeywords($project->id, $settings->device),
            'offset' => $offset,
            'next_offset' => $offset + $limit < $this->rankings->countKeywords($project->id, $settings->device) ? $offset + $limit : null,
            'tracking_enabled' => $settings->enabled,
            'device' => $settings->device->value,
            'frequency' => $settings->frequency,
            'latest' => Present::day($latest),
            'compare' => Present::day($compare),
            'winners' => array_map($move, array_slice(array_values(array_filter($moves, static fn(RankingMove $m): bool => $m->change() > 0)), 0, 10)),
            'losers' => array_map($move, array_slice(array_reverse(array_values(array_filter($moves, static fn(RankingMove $m): bool => $m->change() < 0))), 0, 10)),
            'keywords' => array_map(static fn(RankedKeyword $k): array => [
                'keyword_id' => $k->publicId,
                'keyword' => $k->keyword,
                'market' => $k->locale->label(),
                'checked_on' => $k->checkedOn,
                'position' => $k->position,
                'change' => $k->change(),
                'best_position' => $k->bestPosition,
                'url' => $k->url,
                'serp_features' => $k->features,
                'search_volume' => $k->searchVolume,
            ], $this->rankings->keywords($project->id, $project->workspaceId, $settings->device, $limit, $offset)),
        ];
    }
}
