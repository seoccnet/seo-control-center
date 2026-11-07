<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\IndexNow\IndexNowRepository;
use SeoControlCenter\Modules\IndexNow\IndexNowScheduler;
use SeoControlCenter\Modules\IndexNow\IndexNowSettings;

/**
 * IndexNow settings, sitemap URLs, submissions and queued work in memory.
 */
final class InMemoryIndexNow implements IndexNowRepository, IndexNowScheduler
{
    /** @var array<int, IndexNowSettings> */
    public array $settings = [];

    /** @var array<int, array<string, array{lastmod: ?string, submitted: bool}>> */
    public array $urls = [];

    /** @var array<int, list<array{source: string, url_count: int, status_code: ?int, result: string, created_at: string}>> */
    public array $log = [];

    /** @var list<array{projectId: int, urls: list<string>, userId: ?int}> */
    public array $queuedSubmissions = [];

    /** @var list<int> */
    public array $queuedSitemaps = [];

    public function __construct(private readonly Clock $clock) {}

    public function settings(int $projectId): ?IndexNowSettings
    {
        return $this->settings[$projectId] ?? null;
    }

    public function create(int $projectId, int $workspaceId, string $key): void
    {
        $this->settings[$projectId] = new IndexNowSettings($projectId, $workspaceId, $key, null, null, null, false, null, null);
    }

    public function markVerified(int $projectId, string $host, string $path = '/'): void
    {
        $s = $this->settings[$projectId];
        $this->settings[$projectId] = new IndexNowSettings($projectId, $s->workspaceId, $s->key, $host, $this->now(), $s->sitemapUrl, $s->autoSubmit, $s->sitemapCheckedAt, null, $path);
    }

    public function saveSitemap(int $projectId, ?string $sitemapUrl, bool $autoSubmit): void
    {
        $s = $this->settings[$projectId];
        $this->settings[$projectId] = new IndexNowSettings($projectId, $s->workspaceId, $s->key, $s->host, $s->verifiedAt, $sitemapUrl, $autoSubmit, $s->sitemapCheckedAt, $s->lastError, $s->keyPath);
    }

    public function recordSitemapCheck(int $projectId, ?string $error): void
    {
        $s = $this->settings[$projectId];
        $this->settings[$projectId] = new IndexNowSettings($projectId, $s->workspaceId, $s->key, $s->host, $s->verifiedAt, $s->sitemapUrl, $s->autoSubmit, $this->now(), $error, $s->keyPath);
    }

    public function autoSubmitProjects(): array
    {
        $projects = [];
        foreach ($this->settings as $s) {
            if ($s->autoSubmit && $s->isVerified() && $s->sitemapUrl !== null) {
                $projects[] = ['projectId' => $s->projectId, 'workspaceId' => $s->workspaceId];
            }
        }

        return $projects;
    }

    public function knownUrls(int $projectId): array
    {
        return array_map(static fn(array $u): ?string => $u['lastmod'], $this->urls[$projectId] ?? []);
    }

    public function rememberUrls(int $projectId, array $urls, array $submitted): void
    {
        $set = array_flip($submitted);
        foreach ($urls as $url) {
            $this->urls[$projectId][$url['loc']] = ['lastmod' => $url['lastmod'], 'submitted' => isset($set[$url['loc']]) || ($this->urls[$projectId][$url['loc']]['submitted'] ?? false)];
        }
    }

    public function logSubmission(int $projectId, string $source, int $count, ?int $status, string $result, ?int $userId): void
    {
        $this->log[$projectId] ??= [];
        array_unshift($this->log[$projectId], ['source' => $source, 'url_count' => $count, 'status_code' => $status, 'result' => $result, 'created_at' => $this->now()]);
    }

    public function submissions(int $projectId, int $limit): array
    {
        return array_slice($this->log[$projectId] ?? [], 0, $limit);
    }

    public function delete(int $projectId): void
    {
        unset($this->settings[$projectId], $this->urls[$projectId]);
    }

    public function submit(int $projectId, int $workspaceId, array $urls, ?int $userId): void
    {
        $this->queuedSubmissions[] = ['projectId' => $projectId, 'urls' => $urls, 'userId' => $userId];
    }

    public function checkSitemap(int $projectId, int $workspaceId): void
    {
        $this->queuedSitemaps[] = $projectId;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
