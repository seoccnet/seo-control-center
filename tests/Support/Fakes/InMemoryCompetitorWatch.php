<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Modules\Competitors\CompetitorSitemapReader;
use SeoControlCenter\Modules\Competitors\CompetitorWatchRepository;
use SeoControlCenter\Modules\Competitors\CompetitorWatchScheduler;

/** Competitor content watch (1.6.0) in memory, with scripted sitemaps and recorded queue requests. */
final class InMemoryCompetitorWatch implements CompetitorWatchRepository, CompetitorWatchScheduler, CompetitorSitemapReader
{
    /** @var array<int, bool> */
    public array $enabled = [];

    /** @var array<int, array{projectId: int, checked_on: string, baseline_on: ?string, urls: ?int, capped: bool, error: ?string}> */
    public array $states = [];

    /** @var array<int, array<string, array{projectId: int, first_seen: ?string, removed_on: ?string}>> competitor => url => page */
    public array $pages = [];

    /** @var array<string, list<string>|string> domain => sitemap URLs, or an error message */
    public array $sitemaps = [];

    /** @var list<array{competitorId: int, projectId: int, workspaceId: int, domain: string}> */
    public array $queued = [];

    public function enabled(int $projectId): bool
    {
        return $this->enabled[$projectId] ?? false;
    }

    public function setEnabled(int $projectId, int $workspaceId, bool $enabled): void
    {
        $this->enabled[$projectId] = $enabled;
    }

    public function due(string $today, int $limit): array
    {
        return [];
    }

    public function states(int $projectId): array
    {
        $out = [];
        foreach ($this->states as $id => $state) {
            if ($state['projectId'] === $projectId) {
                $out[$id] = ['checked_on' => $state['checked_on'], 'baseline_on' => $state['baseline_on'], 'urls' => $state['urls'], 'capped' => $state['capped'], 'error' => $state['error']];
            }
        }

        return $out;
    }

    public function record(int $competitorId, int $projectId, array $urls, string $today, bool $capped): array
    {
        $baseline = ($this->states[$competitorId]['baseline_on'] ?? null) === null;
        $added = $removed = 0;
        $listed = array_flip($urls);
        foreach ($urls as $url) {
            if (!isset($this->pages[$competitorId][$url])) {
                $this->pages[$competitorId][$url] = ['projectId' => $projectId, 'first_seen' => $baseline ? null : $today, 'removed_on' => null];
                $added += $baseline ? 0 : 1;
            } else {
                $this->pages[$competitorId][$url]['removed_on'] = null;
            }
        }
        if (!$capped && !$baseline) {
            foreach ($this->pages[$competitorId] as $url => $page) {
                if (!isset($listed[$url]) && $page['removed_on'] === null) {
                    $this->pages[$competitorId][$url]['removed_on'] = $today;
                    $removed++;
                }
            }
        }
        $this->states[$competitorId] = ['projectId' => $projectId, 'checked_on' => $today, 'baseline_on' => $this->states[$competitorId]['baseline_on'] ?? $today, 'urls' => count($listed), 'capped' => $capped, 'error' => null];

        return ['added' => $added, 'removed' => $removed];
    }

    public function fail(int $competitorId, int $projectId, string $today, string $error): void
    {
        $this->states[$competitorId] = ['projectId' => $projectId, 'checked_on' => $today, 'baseline_on' => $this->states[$competitorId]['baseline_on'] ?? null, 'urls' => $this->states[$competitorId]['urls'] ?? null, 'capped' => false, 'error' => $error];
    }

    public function changes(int $projectId, string $since, int $limit): array
    {
        $out = [];
        foreach ($this->pages as $competitorId => $pages) {
            foreach ($pages as $url => $page) {
                if ($page['projectId'] !== $projectId) {
                    continue;
                }
                if ($page['first_seen'] !== null && $page['first_seen'] >= $since) {
                    $out[] = ['competitorId' => $competitorId, 'url' => (string) $url, 'kind' => 'new', 'date' => $page['first_seen']];
                }
                if ($page['removed_on'] !== null && $page['removed_on'] >= $since) {
                    $out[] = ['competitorId' => $competitorId, 'url' => (string) $url, 'kind' => 'removed', 'date' => $page['removed_on']];
                }
            }
        }
        usort($out, static fn(array $a, array $b): int => [$b['date'], $a['kind'], $a['url']] <=> [$a['date'], $b['kind'], $b['url']]);

        return array_slice($out, 0, $limit);
    }

    public function check(int $competitorId, int $projectId, int $workspaceId, string $domain, string $today): void
    {
        $this->queued[] = ['competitorId' => $competitorId, 'projectId' => $projectId, 'workspaceId' => $workspaceId, 'domain' => $domain];
    }

    public function read(string $domain, int $limit): array
    {
        $sitemap = $this->sitemaps[$domain] ?? 'No sitemap found or readable.';
        if (is_string($sitemap)) {
            throw new \RuntimeException($sitemap);
        }

        return ['urls' => array_slice($sitemap, 0, $limit), 'capped' => count($sitemap) >= $limit];
    }
}
