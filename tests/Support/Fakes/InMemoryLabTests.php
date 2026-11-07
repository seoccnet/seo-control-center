<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\LabTests\LabProfile;
use SeoControlCenter\Domain\LabTests\LabResult;
use SeoControlCenter\Domain\LabTests\LabRun;
use SeoControlCenter\Domain\LabTests\LabTestRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\LabTests\LabTestScheduler;

/** Lab test profiles and runs in memory; queued runs are recorded (ADR-040, F2). */
final class InMemoryLabTests implements LabTestRepository, LabTestScheduler
{
    /** @var array<int, LabProfile> */
    public array $profiles = [];
    /** @var array<int, LabRun> */
    public array $runs = [];
    /** @var list<array{run_id: int, delay: int}> */
    public array $queued = [];
    private int $nextId = 1;

    public function __construct(private Clock $clock) {}

    public function queue(int $runId, int $workspaceId, int $delaySeconds = 0): void
    {
        $this->queued[] = ['run_id' => $runId, 'delay' => $delaySeconds];
    }

    public function run(int $runId): ?LabRun
    {
        return $this->runs[$runId] ?? null;
    }

    public function profiles(Project $project): array
    {
        return array_values(array_filter($this->profiles, static fn(LabProfile $p): bool => $p->projectId === $project->id && $p->workspaceId === $project->workspaceId));
    }

    public function profile(Project $project, string $publicId): ?LabProfile
    {
        foreach ($this->profiles($project) as $profile) {
            if ($profile->publicId === $publicId) {
                return $profile;
            }
        }

        return null;
    }

    public function profileById(int $profileId): ?LabProfile
    {
        return $this->profiles[$profileId] ?? null;
    }

    public function createProfile(Project $project, string $url, string $device, string $source, bool $weekly, ?string $nextRunAt, ?int $userId, string $now): ?LabProfile
    {
        foreach ($this->profiles($project) as $p) {
            if ($p->url === $url && $p->device === $device && $p->source === $source) {
                return null;
            }
        }
        $id = $this->nextId++;

        return $this->profiles[$id] = new LabProfile($id, Uuid::v7($this->clock), $project->id, $project->workspaceId, $url, $device, $source, $weekly, $nextRunAt, $now);
    }

    public function countProfiles(int $projectId): int
    {
        return count(array_filter($this->profiles, static fn(LabProfile $p): bool => $p->projectId === $projectId));
    }

    public function deleteProfile(Project $project, string $publicId): bool
    {
        $profile = $this->profile($project, $publicId);
        if ($profile === null) {
            return false;
        }
        unset($this->profiles[$profile->id]);
        $this->runs = array_filter($this->runs, static fn(LabRun $r): bool => $r->profileId !== $profile->id);

        return true;
    }

    public function setWeekly(Project $project, string $publicId, bool $weekly, ?string $nextRunAt): bool
    {
        $p = $this->profile($project, $publicId);
        if ($p === null) {
            return false;
        }
        $this->profiles[$p->id] = new LabProfile($p->id, $p->publicId, $p->projectId, $p->workspaceId, $p->url, $p->device, $p->source, $weekly, $nextRunAt, $p->createdAt);

        return true;
    }

    public function createRun(LabProfile $profile, string $trigger, ?int $userId, string $now): LabRun
    {
        $id = $this->nextId++;

        return $this->runs[$id] = new LabRun($id, Uuid::v7($this->clock), $profile->id, $profile->projectId, $profile->workspaceId, LabRun::QUEUED, $trigger, null, 0, null, null, null, $now, null);
    }

    public function hasActiveRun(int $profileId): bool
    {
        foreach ($this->runs as $run) {
            if ($run->profileId === $profileId && $run->active()) {
                return true;
            }
        }

        return false;
    }

    public function failStale(string $before, string $now): void
    {
        foreach ($this->runs as $id => $run) {
            if ($run->active() && $run->createdAt < $before) {
                $this->runs[$id] = $this->with($run, status: LabRun::FAILED, error: 'Stopped: no result within two hours.', finishedAt: $now);
            }
        }
    }

    public function markRunning(int $runId): void
    {
        $this->runs[$runId] = $this->with($this->runs[$runId], status: LabRun::RUNNING);
    }

    public function saveTask(int $runId, string $taskId, float $cost): void
    {
        $this->runs[$runId] = $this->with($this->runs[$runId], taskId: $taskId, cost: $cost);
    }

    public function polled(int $runId): void
    {
        $this->runs[$runId] = $this->with($this->runs[$runId], polls: $this->runs[$runId]->polls + 1);
    }

    public function finish(int $runId, LabResult $result, string $now): void
    {
        if ($this->runs[$runId]->active()) {
            $this->runs[$runId] = $this->with($this->runs[$runId], status: LabRun::DONE, result: $result, finishedAt: $now);
        }
    }

    public function fail(int $runId, string $error, string $now): void
    {
        if ($this->runs[$runId]->active()) {
            $this->runs[$runId] = $this->with($this->runs[$runId], status: LabRun::FAILED, error: $error, finishedAt: $now);
        }
    }

    public function recentRuns(int $projectId, int $perProfile): array
    {
        $runs = array_reverse(array_values(array_filter($this->runs, static fn(LabRun $r): bool => $r->projectId === $projectId)));
        $out = [];
        foreach ($runs as $run) {
            if (count($out[$run->profileId] ?? []) < $perProfile) {
                $out[$run->profileId][] = $run;
            }
        }

        return $out;
    }

    public function due(string $now, int $limit): array
    {
        return array_slice(array_values(array_filter($this->profiles, static fn(LabProfile $p): bool => $p->weekly && $p->nextRunAt !== null && $p->nextRunAt <= $now)), 0, $limit);
    }

    public function scheduleNext(int $profileId, string $nextRunAt): void
    {
        $p = $this->profiles[$profileId];
        $this->profiles[$profileId] = new LabProfile($p->id, $p->publicId, $p->projectId, $p->workspaceId, $p->url, $p->device, $p->source, $p->weekly, $nextRunAt, $p->createdAt);
    }

    public function prune(int $profileId, int $keep): void
    {
        $finished = array_reverse(array_keys(array_filter($this->runs, static fn(LabRun $r): bool => $r->profileId === $profileId && !$r->active())));
        foreach (array_slice($finished, $keep) as $id) {
            unset($this->runs[$id]);
        }
    }

    private function with(LabRun $r, ?string $status = null, ?string $taskId = null, ?int $polls = null, ?LabResult $result = null, ?string $error = null, ?float $cost = null, ?string $finishedAt = null): LabRun
    {
        return new LabRun($r->id, $r->publicId, $r->profileId, $r->projectId, $r->workspaceId, $status ?? $r->status, $r->trigger, $taskId ?? $r->taskId, $polls ?? $r->polls, $result ?? $r->result, $error ?? $r->error, $cost ?? $r->cost, $r->createdAt, $finishedAt ?? $r->finishedAt);
    }
}
