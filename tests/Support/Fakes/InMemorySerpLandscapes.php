<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Opportunities\SerpLandscape;
use SeoControlCenter\Domain\Opportunities\SerpLandscapeRepository;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Rankings\SerpSnapshot;

/**
 * Latest results pages per tracked keyword in memory. Tests can also put a
 * landscape directly into $serps.
 */
final class InMemorySerpLandscapes implements SerpLandscapeRepository
{
    /** @var array<int, array<string, array<string, SerpLandscape>>> project => device => keyword => landscape */
    public array $serps = [];

    public function __construct(private readonly InMemoryKeywords $keywords) {}

    public function record(int $projectId, int $projectKeywordId, string $date, SerpSnapshot $serp): void
    {
        $text = null;
        foreach ($this->keywords->keywords[$projectId] ?? [] as $keyword) {
            if ($keyword['id'] === $projectKeywordId) {
                $text = Keyword::normalize($keyword['keyword']);
            }
        }
        if ($text === null) {
            return;
        }
        $current = $this->serps[$projectId][$serp->device->value][$text] ?? null;
        if ($current !== null && $current->checkedOn > $date) {
            return;
        }
        $results = [];
        foreach (array_slice($serp->organic, 0, self::TOP) as $r) {
            $results[] = ['position' => $r->position, 'domain' => $r->domain, 'url' => $r->url, 'title' => $r->title];
        }
        $this->serps[$projectId][$serp->device->value][$text] = new SerpLandscape($text, $date, $results, $serp->features, $serp->aiOverview !== null);
    }

    public function latest(int $projectId, SerpDevice $device): array
    {
        return $this->serps[$projectId][$device->value] ?? [];
    }

    public function domains(int $projectId, SerpDevice $device, int $limit): array
    {
        $counts = [];
        foreach ($this->latest($projectId, $device) as $serp) {
            foreach ($serp->domains() as $domain) {
                $counts[$domain] = ($counts[$domain] ?? 0) + 1;
            }
        }
        uksort($counts, static fn(string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);

        return array_slice(array_keys($counts), 0, $limit);
    }
}
