<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\LabTests;

use SeoControlCenter\Domain\Projects\Project;

/**
 * Lab test profiles and runs (ADR-040, F2). Reads by project are bound to
 * the project's workspace; run IDs come from queued jobs only.
 */
interface LabTestRepository
{
    /** @return list<LabProfile> oldest first */
    public function profiles(Project $project): array;

    public function profile(Project $project, string $publicId): ?LabProfile;

    public function profileById(int $profileId): ?LabProfile;

    /** Null when the project already has this page, device and source. */
    public function createProfile(Project $project, string $url, string $device, string $source, bool $weekly, ?string $nextRunAt, ?int $userId, string $now): ?LabProfile;

    public function countProfiles(int $projectId): int;

    public function deleteProfile(Project $project, string $publicId): bool;

    public function setWeekly(Project $project, string $publicId, bool $weekly, ?string $nextRunAt): bool;

    public function createRun(LabProfile $profile, string $trigger, ?int $userId, string $now): LabRun;

    public function run(int $runId): ?LabRun;

    public function hasActiveRun(int $profileId): bool;

    /** Marks runs still waiting or running since before $before as failed (a lost job). */
    public function failStale(string $before, string $now): void;

    public function markRunning(int $runId): void;

    public function saveTask(int $runId, string $taskId, float $cost): void;

    public function polled(int $runId): void;

    public function finish(int $runId, LabResult $result, string $now): void;

    public function fail(int $runId, string $error, string $now): void;

    /**
     * The newest runs of each profile of a project, newest first.
     *
     * @return array<int, list<LabRun>> by profile ID
     */
    public function recentRuns(int $projectId, int $perProfile): array;

    /**
     * Weekly profiles due by $now in active projects.
     *
     * @return list<LabProfile>
     */
    public function due(string $now, int $limit): array;

    public function scheduleNext(int $profileId, string $nextRunAt): void;

    /** Keeps the newest $keep finished runs of a profile. */
    public function prune(int $profileId, int $keep): void;
}
