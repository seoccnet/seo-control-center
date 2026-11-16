<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\Backlinks\LinkTargetRepository;

final readonly class PdoLinkTargetRepository implements LinkTargetRepository
{
    public function __construct(private Connection $db, private Clock $clock) {}

    public function run(int $projectId): ?array
    {
        $row = $this->db->fetchOne('SELECT state, error, queued_at, finished_at FROM backlink_target_runs WHERE project_id = ?', [$projectId]);
        if ($row === null) {
            return null;
        }

        return [
            'state' => (string) $row['state'],
            'error' => $row['error'] !== null ? (string) $row['error'] : null,
            'queued_at' => (string) $row['queued_at'],
            'finished_at' => $row['finished_at'] !== null ? (string) $row['finished_at'] : null,
        ];
    }

    public function setState(int $projectId, int $workspaceId, string $state, ?string $error = null): void
    {
        $now = $this->now();
        $finished = in_array($state, [self::DONE, self::FAILED], true) ? $now : null;
        if ($state === self::QUEUED) {
            $this->db->execute(
                'INSERT INTO backlink_target_runs (project_id, workspace_id, state, error, queued_at, finished_at) VALUES (?, ?, ?, NULL, ?, NULL)
                 ON DUPLICATE KEY UPDATE state = VALUES(state), error = NULL, queued_at = VALUES(queued_at), finished_at = NULL',
                [$projectId, $workspaceId, $state, $now],
            );

            return;
        }
        $this->db->execute(
            'UPDATE backlink_target_runs SET state = ?, error = ?, finished_at = ? WHERE project_id = ? AND workspace_id = ?',
            [$state, $error !== null ? mb_substr($error, 0, 255) : null, $finished, $projectId, $workspaceId],
        );
    }

    public function results(int $projectId): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT url, status, location, final_status, error, checked_at FROM backlink_target_checks WHERE project_id = ?', [$projectId]) as $row) {
            $out[(string) $row['url']] = [
                'status' => $row['status'] !== null ? (int) $row['status'] : null,
                'location' => $row['location'] !== null ? (string) $row['location'] : null,
                'final_status' => $row['final_status'] !== null ? (int) $row['final_status'] : null,
                'error' => $row['error'] !== null ? (string) $row['error'] : null,
                'checked_at' => (string) $row['checked_at'],
            ];
        }

        return $out;
    }

    public function save(int $projectId, string $url, ?int $status, ?string $location, ?int $finalStatus, ?string $error): void
    {
        $this->db->execute(
            'INSERT INTO backlink_target_checks (project_id, url_hash, url, status, location, final_status, error, checked_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), location = VALUES(location), final_status = VALUES(final_status), error = VALUES(error), checked_at = VALUES(checked_at)',
            [$projectId, hash('sha256', $url), mb_substr($url, 0, 2048), $status, $location !== null ? mb_substr($location, 0, 2048) : null, $finalStatus, $error !== null ? mb_substr($error, 0, 255) : null, $this->now()],
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
