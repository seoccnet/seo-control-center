<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks;

/**
 * Checks of the project pages that backlinks point to (1.5.0), and the
 * state of the latest check run per project.
 */
interface LinkTargetRepository
{
    public const string QUEUED = 'queued';
    public const string RUNNING = 'running';
    public const string DONE = 'done';
    public const string FAILED = 'failed';

    /** @return array{state: string, error: ?string, queued_at: string, finished_at: ?string}|null */
    public function run(int $projectId): ?array;

    /** Marks a new run queued (or updates the state of the current one). */
    public function setState(int $projectId, int $workspaceId, string $state, ?string $error = null): void;

    /**
     * @return array<string, array{status: ?int, location: ?string, final_status: ?int, error: ?string, checked_at: string}> url => answer
     */
    public function results(int $projectId): array;

    public function save(int $projectId, string $url, ?int $status, ?string $location, ?int $finalStatus, ?string $error): void;
}
