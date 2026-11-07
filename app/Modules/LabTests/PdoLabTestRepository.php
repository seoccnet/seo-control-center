<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\LabTests;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\LabTests\LabDiagnostics;
use SeoControlCenter\Domain\LabTests\LabProfile;
use SeoControlCenter\Domain\LabTests\LabResult;
use SeoControlCenter\Domain\LabTests\LabRun;
use SeoControlCenter\Domain\LabTests\LabTestRepository;
use SeoControlCenter\Domain\Projects\Project;

final readonly class PdoLabTestRepository implements LabTestRepository
{
    private const string PROFILE = 'p.id, p.public_id, p.project_id, p.workspace_id, p.url, p.device, p.source, p.weekly, p.next_run_at, p.created_at';
    private const string RUN = 'r.id, r.public_id, r.profile_id, r.project_id, r.workspace_id, r.status, r.trigger_type, r.task_id, r.polls, r.score, r.lcp_ms, r.cls, r.tbt_ms, r.fcp_ms, r.si_ms, r.lighthouse_version, r.diagnostics, r.error, r.cost, r.created_at, r.finished_at';

    public function __construct(private Connection $db, private Clock $clock) {}

    public function profiles(Project $project): array
    {
        return array_map(self::profileRow(...), $this->db->fetchAll('SELECT ' . self::PROFILE . ' FROM lab_profiles p WHERE p.workspace_id = ? AND p.project_id = ? ORDER BY p.id LIMIT 100', [$project->workspaceId, $project->id]));
    }

    public function profile(Project $project, string $publicId): ?LabProfile
    {
        $row = $this->db->fetchOne('SELECT ' . self::PROFILE . ' FROM lab_profiles p WHERE p.workspace_id = ? AND p.project_id = ? AND p.public_id = ?', [$project->workspaceId, $project->id, $publicId]);

        return $row !== null ? self::profileRow($row) : null;
    }

    public function profileById(int $profileId): ?LabProfile
    {
        $row = $this->db->fetchOne('SELECT ' . self::PROFILE . ' FROM lab_profiles p WHERE p.id = ?', [$profileId]);

        return $row !== null ? self::profileRow($row) : null;
    }

    public function createProfile(Project $project, string $url, string $device, string $source, bool $weekly, ?string $nextRunAt, ?int $userId, string $now): ?LabProfile
    {
        $publicId = Uuid::v7($this->clock);
        $inserted = $this->db->execute(
            'INSERT IGNORE INTO lab_profiles (public_id, workspace_id, project_id, url, url_hash, device, source, weekly, next_run_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$publicId, $project->workspaceId, $project->id, $url, hash('sha256', $url), $device, $source, $weekly ? 1 : 0, $nextRunAt, $userId, $now],
        );

        return $inserted === 1 ? $this->profile($project, $publicId) : null;
    }

    public function countProfiles(int $projectId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM lab_profiles WHERE project_id = ?', [$projectId]);
    }

    public function deleteProfile(Project $project, string $publicId): bool
    {
        return $this->db->execute('DELETE FROM lab_profiles WHERE workspace_id = ? AND project_id = ? AND public_id = ?', [$project->workspaceId, $project->id, $publicId]) === 1;
    }

    public function setWeekly(Project $project, string $publicId, bool $weekly, ?string $nextRunAt): bool
    {
        if ($this->profile($project, $publicId) === null) {
            return false;
        }
        $this->db->execute('UPDATE lab_profiles SET weekly = ?, next_run_at = ? WHERE workspace_id = ? AND project_id = ? AND public_id = ?', [$weekly ? 1 : 0, $nextRunAt, $project->workspaceId, $project->id, $publicId]);

        return true;
    }

    public function createRun(LabProfile $profile, string $trigger, ?int $userId, string $now): LabRun
    {
        $publicId = Uuid::v7($this->clock);
        $this->db->execute(
            "INSERT INTO lab_runs (public_id, profile_id, project_id, workspace_id, status, trigger_type, requested_by, created_at) VALUES (?, ?, ?, ?, 'queued', ?, ?, ?)",
            [$publicId, $profile->id, $profile->projectId, $profile->workspaceId, $trigger, $userId, $now],
        );

        return $this->run($this->db->lastInsertId()) ?? throw new \RuntimeException('Lab run missing after insert.');
    }

    public function run(int $runId): ?LabRun
    {
        $row = $this->db->fetchOne('SELECT ' . self::RUN . ' FROM lab_runs r WHERE r.id = ?', [$runId]);

        return $row !== null ? self::runRow($row) : null;
    }

    public function hasActiveRun(int $profileId): bool
    {
        return $this->db->fetchValue("SELECT 1 FROM lab_runs WHERE profile_id = ? AND status IN ('queued', 'running') LIMIT 1", [$profileId]) !== null;
    }

    public function failStale(string $before, string $now): void
    {
        $this->db->execute("UPDATE lab_runs SET status = 'failed', error = 'Stopped: no result within two hours.', finished_at = ? WHERE status IN ('queued', 'running') AND created_at < ?", [$now, $before]);
    }

    public function markRunning(int $runId): void
    {
        $this->db->execute("UPDATE lab_runs SET status = 'running' WHERE id = ? AND status IN ('queued', 'running')", [$runId]);
    }

    public function saveTask(int $runId, string $taskId, float $cost): void
    {
        $this->db->execute('UPDATE lab_runs SET task_id = ?, cost = ? WHERE id = ?', [$taskId, $cost, $runId]);
    }

    public function polled(int $runId): void
    {
        $this->db->execute('UPDATE lab_runs SET polls = polls + 1 WHERE id = ?', [$runId]);
    }

    public function finish(int $runId, LabResult $result, string $now): void
    {
        $this->db->execute(
            "UPDATE lab_runs SET status = 'done', score = ?, lcp_ms = ?, cls = ?, tbt_ms = ?, fcp_ms = ?, si_ms = ?, lighthouse_version = ?, diagnostics = ?, error = NULL, finished_at = ? WHERE id = ? AND status IN ('queued', 'running')",
            [$result->score, $result->lcpMs, $result->cls, $result->tbtMs, $result->fcpMs, $result->siMs, $result->version, $result->diagnostics !== null ? json_encode($result->diagnostics->present(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) : null, $now, $runId],
        );
    }

    public function fail(int $runId, string $error, string $now): void
    {
        $this->db->execute("UPDATE lab_runs SET status = 'failed', error = ?, finished_at = ? WHERE id = ? AND status IN ('queued', 'running')", [mb_substr($error, 0, 500), $now, $runId]);
    }

    public function recentRuns(int $projectId, int $perProfile): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT ' . self::RUN . ' FROM lab_runs r WHERE r.project_id = ? ORDER BY r.profile_id, r.id DESC LIMIT 2000', [$projectId]) as $row) {
            $run = self::runRow($row);
            if (count($out[$run->profileId] ?? []) < $perProfile) {
                $out[$run->profileId][] = $run;
            }
        }

        return $out;
    }

    public function due(string $now, int $limit): array
    {
        return array_map(self::profileRow(...), $this->db->fetchAll(
            sprintf('SELECT %s FROM lab_profiles p
                       JOIN projects pr ON pr.id = p.project_id AND pr.workspace_id = p.workspace_id AND pr.deleted_at IS NULL
                       JOIN workspaces w ON w.id = p.workspace_id AND w.deleted_at IS NULL
                      WHERE p.weekly = 1 AND p.next_run_at IS NOT NULL AND p.next_run_at <= ?
                      ORDER BY p.next_run_at LIMIT %d', self::PROFILE, max(1, $limit)),
            [$now],
        ));
    }

    public function scheduleNext(int $profileId, string $nextRunAt): void
    {
        $this->db->execute('UPDATE lab_profiles SET next_run_at = ? WHERE id = ?', [$nextRunAt, $profileId]);
    }

    public function prune(int $profileId, int $keep): void
    {
        $ids = array_map(static fn(array $r): int => (int) $r['id'], $this->db->fetchAll(sprintf("SELECT id FROM lab_runs WHERE profile_id = ? AND status IN ('done', 'failed') ORDER BY id DESC LIMIT 500 OFFSET %d", max(1, $keep)), [$profileId]));
        foreach (array_chunk($ids, 200) as $chunk) {
            $this->db->execute('DELETE FROM lab_runs WHERE profile_id = ? AND id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', [$profileId, ...$chunk]);
        }
    }

    /** @param array<string, mixed> $row */
    private static function profileRow(array $row): LabProfile
    {
        return new LabProfile((int) $row['id'], (string) $row['public_id'], (int) $row['project_id'], (int) $row['workspace_id'], (string) $row['url'], (string) $row['device'], (string) $row['source'], (bool) $row['weekly'], $row['next_run_at'] !== null ? (string) $row['next_run_at'] : null, (string) $row['created_at']);
    }

    /** @param array<string, mixed> $row */
    private static function runRow(array $row): LabRun
    {
        $result = $row['score'] !== null && $row['lcp_ms'] !== null && $row['cls'] !== null
            ? new LabResult((float) $row['score'], (float) $row['lcp_ms'], (float) $row['cls'], $row['tbt_ms'] !== null ? (float) $row['tbt_ms'] : null, $row['fcp_ms'] !== null ? (float) $row['fcp_ms'] : null, $row['si_ms'] !== null ? (float) $row['si_ms'] : null, $row['lighthouse_version'] !== null ? (string) $row['lighthouse_version'] : null, $row['diagnostics'] !== null ? LabDiagnostics::fromArray(json_decode((string) $row['diagnostics'], true)) : null)
            : null;

        return new LabRun((int) $row['id'], (string) $row['public_id'], (int) $row['profile_id'], (int) $row['project_id'], (int) $row['workspace_id'], (string) $row['status'], (string) $row['trigger_type'], $row['task_id'] !== null ? (string) $row['task_id'] : null, (int) $row['polls'], $result, $row['error'] !== null ? (string) $row['error'] : null, $row['cost'] !== null ? (float) $row['cost'] : null, (string) $row['created_at'], $row['finished_at'] !== null ? (string) $row['finished_at'] : null);
    }
}
