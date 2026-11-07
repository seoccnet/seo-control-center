<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Rankings\AiOverview;
use SeoControlCenter\Domain\Rankings\AiOverviewReference;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Modules\AiVisibility\AiOverviewKeyword;
use SeoControlCenter\Modules\AiVisibility\AiOverviewRepository;

/**
 * AI Overview history and latest sources in memory.
 */
final class InMemoryAiOverviews implements AiOverviewRepository
{
    /** @var array<string, array{projectId: int, keywordId: int, device: SerpDevice, date: string, present: bool, ownPosition: ?int, ownUrl: ?string}> keyed by keyword:device:date */
    public array $snapshots = [];

    /** @var array<string, array{projectId: int, keywordId: int, device: SerpDevice, references: list<AiOverviewReference>}> keyed by keyword:device */
    public array $references = [];

    public function __construct(
        private readonly InMemoryDirectory $directory,
        private readonly InMemoryKeywords $keywords,
        private readonly InMemoryRankings $rankings,
    ) {}

    public function projectDomain(int $projectId): ?string
    {
        $project = $this->directory->projects[$projectId] ?? null;
        if ($project === null) {
            return null;
        }
        $domain = strtolower($project->primaryDomain);

        return str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
    }

    public function record(int $projectId, int $projectKeywordId, SerpDevice $device, string $date, ?AiOverview $overview, ?int $ownPosition, ?string $ownUrl): void
    {
        $this->snapshots[$projectKeywordId . ':' . $device->value . ':' . $date] = [
            'projectId' => $projectId, 'keywordId' => $projectKeywordId, 'device' => $device, 'date' => $date,
            'present' => $overview !== null, 'ownPosition' => $ownPosition, 'ownUrl' => $ownUrl,
        ];
        $this->references[$projectKeywordId . ':' . $device->value] = ['projectId' => $projectId, 'keywordId' => $projectKeywordId, 'device' => $device, 'references' => $overview !== null ? $overview->references : []];
    }

    public function latestDate(int $projectId, SerpDevice $device): ?string
    {
        $dates = array_map(static fn(array $s): string => $s['date'], $this->for($projectId, $device));

        return $dates !== [] ? max($dates) : null;
    }

    public function dateOnOrBefore(int $projectId, SerpDevice $device, string $date): ?string
    {
        $dates = array_filter(array_map(static fn(array $s): string => $s['date'], $this->for($projectId, $device)), static fn(string $d): bool => $d <= $date);

        return $dates !== [] ? max($dates) : null;
    }

    public function summary(int $projectId, SerpDevice $device, string $date): array
    {
        $rows = array_filter($this->for($projectId, $device), static fn(array $s): bool => $s['date'] === $date);

        return [
            'keywords' => count($rows),
            'present' => count(array_filter($rows, static fn(array $s): bool => $s['present'])),
            'cited' => count(array_filter($rows, static fn(array $s): bool => $s['ownPosition'] !== null)),
        ];
    }

    public function keywords(int $projectId, SerpDevice $device, string $date, bool $onlyWithOverview, int $limit, int $offset): array
    {
        $texts = [];
        foreach ($this->keywords->keywords[$projectId] ?? [] as $keyword) {
            $texts[$keyword['id']] = $keyword;
        }
        $rows = [];
        foreach ($this->for($projectId, $device) as $s) {
            if ($s['date'] !== $date || ($onlyWithOverview && !$s['present']) || !isset($texts[$s['keywordId']])) {
                continue;
            }
            $rank = $this->rankings->snapshots[$s['keywordId'] . ':' . $device->value . ':' . $date] ?? null;
            $rows[] = new AiOverviewKeyword(
                $texts[$s['keywordId']]['public_id'],
                $texts[$s['keywordId']]['keyword'],
                $rank?->position,
                $s['present'],
                $s['ownPosition'],
                $s['ownUrl'],
                array_values(array_unique(array_map(static fn(AiOverviewReference $r): string => $r->domain, $this->references[$s['keywordId'] . ':' . $device->value]['references'] ?? []))),
            );
        }
        usort($rows, static fn(AiOverviewKeyword $a, AiOverviewKeyword $b): int => [!$a->present, $a->ownPosition === null, $a->ownPosition, $a->keyword] <=> [!$b->present, $b->ownPosition === null, $b->ownPosition, $b->keyword]);

        return array_slice($rows, $offset, $limit);
    }

    public function countKeywords(int $projectId, SerpDevice $device, string $date, bool $onlyWithOverview): int
    {
        return count(array_filter($this->for($projectId, $device), static fn(array $s): bool => $s['date'] === $date && (!$onlyWithOverview || $s['present'])));
    }

    public function topDomains(int $projectId, SerpDevice $device, int $limit): array
    {
        $counts = [];
        foreach ($this->references as $row) {
            if ($row['projectId'] !== $projectId || $row['device'] !== $device) {
                continue;
            }
            foreach (array_unique(array_map(static fn(AiOverviewReference $r): string => $r->domain, $row['references'])) as $domain) {
                $counts[$domain] = ($counts[$domain] ?? 0) + 1;
            }
        }
        uksort($counts, static fn(string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);

        return array_slice(array_map(static fn(string $d, int $n): array => ['domain' => $d, 'keywords' => $n], array_keys($counts), array_values($counts)), 0, $limit);
    }

    /**
     * @return list<array{projectId: int, keywordId: int, device: SerpDevice, date: string, present: bool, ownPosition: ?int, ownUrl: ?string}>
     */
    private function for(int $projectId, SerpDevice $device): array
    {
        return array_values(array_filter($this->snapshots, static fn(array $s): bool => $s['projectId'] === $projectId && $s['device'] === $device));
    }
}
