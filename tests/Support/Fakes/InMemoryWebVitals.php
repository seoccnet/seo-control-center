<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\WebVitals\WebVitalRecord;
use SeoControlCenter\Modules\WebVitals\WebVitalsRepository;
use SeoControlCenter\Modules\WebVitals\WebVitalsScheduler;

final class InMemoryWebVitals implements WebVitalsRepository, WebVitalsScheduler
{
    /** @var array<int, string> */
    private array $keys = [];
    /** @var array<int, array<string, WebVitalRecord>> */
    public array $records = [];
    /** @var list<array{project_id: int, workspace_id: int, project_public_id: string, url: string}> */
    public array $queued = [];
    /** @var array<int, array<string, list<WebVitalRecord>>> */
    private array $histories = [];
    /** @var array<int, array<string, array{url: string, form_factor: string, at: ?string}>> */
    public array $watches = [];
    public function hasKey(int $workspaceId): bool
    {
        return isset($this->keys[$workspaceId]);
    }
    public function apiKey(int $workspaceId): ?string
    {
        return $this->keys[$workspaceId] ?? null;
    }
    public function saveKey(int $workspaceId, #[\SensitiveParameter] string $key): void
    {
        $this->keys[$workspaceId] = $key;
    }
    public function deleteKey(int $workspaceId): void
    {
        unset($this->keys[$workspaceId]);
    }
    public function save(int $projectId, WebVitalRecord $record): void
    {
        $name = $record->url . ($record->formFactor === 'PHONE' ? '' : ':' . $record->formFactor);
        $this->records[$projectId][$name] = $record;
        $history = $this->histories[$projectId][$name] ?? [];
        $history = array_values(array_filter($history, static fn(WebVitalRecord $r): bool => $r->fetchedAt !== $record->fetchedAt));
        $history[] = $record;
        usort($history, static fn(WebVitalRecord $a, WebVitalRecord $b): int => strcmp($b->fetchedAt, $a->fetchedAt));
        $this->histories[$projectId][$name] = array_slice($history, 0, 52);
    }
    public function find(int $projectId, string $url, string $formFactor = 'PHONE'): ?WebVitalRecord
    {
        return $this->records[$projectId][$url . ($formFactor === 'PHONE' ? '' : ':' . $formFactor)] ?? null;
    }
    public function list(int $projectId, int $limit = 100, string $formFactor = 'PHONE'): array
    {
        return array_slice(array_values(array_filter($this->records[$projectId] ?? [], static fn(WebVitalRecord $r): bool => $r->formFactor === $formFactor)), 0, max(1, min(2000, $limit)));
    }
    public function fetch(int $projectId, int $workspaceId, string $projectPublicId, string $url, string $formFactor = 'PHONE'): void
    {
        $this->queued[] = ['project_id' => $projectId, 'workspace_id' => $workspaceId, 'project_public_id' => $projectPublicId, 'url' => $url];
        if ($formFactor !== 'PHONE') {
            $this->queued[count($this->queued) - 1]['form_factor'] = $formFactor;
        }
    }
    public function history(int $projectId, string $url, string $formFactor = 'PHONE'): array
    {
        return $this->histories[$projectId][$url . ($formFactor === 'PHONE' ? '' : ':' . $formFactor)] ?? [];
    }
    public function watch(int $projectId, string $url, string $formFactor, bool $enabled): void
    {
        if ($enabled) {
            $this->watches[$projectId][$url . ':' . $formFactor] ??= ['url' => $url, 'form_factor' => $formFactor, 'at' => null];
        } else {
            unset($this->watches[$projectId][$url . ':' . $formFactor]);
        }
    }
    public function watching(int $projectId, string $url, string $formFactor): bool
    {
        return isset($this->watches[$projectId][$url . ':' . $formFactor]);
    }
    public function due(string $before): array
    {
        // Scheduled persistence selection is covered against real MySQL.
        return [];
    }
    public function watchCount(int $projectId): int
    {
        return count($this->watches[$projectId] ?? []);
    }
    public function attempted(int $projectId, string $url, string $formFactor, string $at): void
    {
        if (isset($this->watches[$projectId][$url . ':' . $formFactor])) {
            $this->watches[$projectId][$url . ':' . $formFactor]['at'] = $at;
        }
    }
}
