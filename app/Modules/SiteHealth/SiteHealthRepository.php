<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteHealth;

/** Site health state and events (1.3.0). Reads take the project; callers authorize it first. */
interface SiteHealthRepository
{
    public function state(int $projectId): SiteHealthState;

    public function save(int $workspaceId, SiteHealthState $state, string $now): void;

    public function setEnabled(int $projectId, int $workspaceId, bool $enabled, string $now): void;

    /**
     * Active projects whose check of this kind is older than $before, oldest first.
     *
     * @param 'uptime'|'daily' $kind
     * @return list<array{projectId: int, workspaceId: int, domain: string}>
     */
    public function due(string $kind, string $before, int $limit): array;

    /**
     * Records an event once per (project, key); false when it was recorded before.
     */
    public function addEvent(int $projectId, string $kind, string $key, string $title, string $detail, string $now): bool;

    /**
     * @return list<array{id: string, kind: string, title: string, detail: string, created_at: string}> newest first
     */
    public function events(int $projectId, ?string $since, int $limit): array;

    /** Keeps the newest $keep events of each project. */
    public function pruneEvents(int $keep): void;
}
