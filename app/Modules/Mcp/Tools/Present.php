<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Rankings\RankingDay;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;

/**
 * Plain-array views of domain objects for tool results. Only public IDs are
 * exposed, never internal database IDs.
 */
final class Present
{
    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function project(Project $project): array
    {
        return ['project_id' => $project->publicId, 'name' => $project->name, 'domain' => $project->primaryDomain, 'timezone' => $project->timezone];
    }

    /**
     * @return array<string, mixed>
     */
    public static function metrics(Metrics $m): array
    {
        return ['clicks' => $m->clicks, 'impressions' => $m->impressions, 'ctr' => round($m->ctr(), 4), 'position' => round($m->position, 1)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(DimensionRow $row): array
    {
        return ['key' => $row->key, ...self::metrics($row->metrics)];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function day(?RankingDay $day): ?array
    {
        return $day === null ? null : [
            'date' => $day->date,
            'keywords' => $day->keywords,
            'ranked' => $day->ranked,
            'top3' => $day->top3,
            'top10' => $day->top10,
            'average_position' => $day->averagePosition,
            'visibility_percent' => round($day->visibility, 2),
        ];
    }

    /**
     * JSON Schema for tools that take only a project.
     *
     * @param array<string, array<string, mixed>> $extra more properties
     * @return array<string, mixed>
     */
    public static function projectSchema(array $extra = []): array
    {
        return [
            'type' => 'object',
            'properties' => ['project_id' => ['type' => 'string', 'description' => 'Project ID from projects_list.'], ...$extra],
            'required' => ['project_id'],
            'additionalProperties' => false,
        ];
    }
}
