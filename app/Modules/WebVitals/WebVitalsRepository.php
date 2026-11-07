<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\WebVitals;

use SeoControlCenter\Domain\WebVitals\WebVitalRecord;

interface WebVitalsRepository
{
    public function hasKey(int $workspaceId): bool;
    public function apiKey(int $workspaceId): ?string;
    public function saveKey(int $workspaceId, #[\SensitiveParameter] string $key): void;
    public function deleteKey(int $workspaceId): void;
    public function save(int $projectId, WebVitalRecord $record): void;
    public function find(int $projectId, string $url, string $formFactor = 'PHONE'): ?WebVitalRecord;
    /** @return list<WebVitalRecord> */
    public function list(int $projectId, int $limit = 100, string $formFactor = 'PHONE'): array;

    /** @return list<WebVitalRecord> newest first, at most 52 snapshots per page/device */
    public function history(int $projectId, string $url, string $formFactor = 'PHONE'): array;

    public function watch(int $projectId, string $url, string $formFactor, bool $enabled): void;
    public function watching(int $projectId, string $url, string $formFactor): bool;
    public function watchCount(int $projectId): int;
    /** @return list<array{project_id: int, workspace_id: int, project_public_id: string, url: string, form_factor: string}> */
    public function due(string $before): array;
    public function attempted(int $projectId, string $url, string $formFactor, string $at): void;
}
