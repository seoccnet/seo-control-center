<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\Research\ResearchRepository;
use SeoControlCenter\Modules\Research\ResearchResult;

final readonly class PdoResearchRepository implements ResearchRepository
{
    private const string COLUMNS = 'project_id, kind, subject, status, params, items, error, requested_at, fetched_at';

    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function find(int $projectId, string $kind, string $subject = ''): ?ResearchResult
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM research_results WHERE project_id = ? AND kind = ? AND subject = ?', [$projectId, $kind, $subject]);

        return $row !== null ? self::result($row) : null;
    }

    public function forKind(int $projectId, string $kind): array
    {
        return array_map(
            self::result(...),
            $this->db->fetchAll('SELECT ' . self::COLUMNS . ' FROM research_results WHERE project_id = ? AND kind = ? ORDER BY requested_at DESC, id DESC LIMIT 50', [$projectId, $kind]),
        );
    }

    public function queue(int $projectId, int $workspaceId, string $kind, string $subject, array $params, ?int $userId): void
    {
        $params['_request_id'] = \SeoControlCenter\Core\Support\Uuid::v7($this->clock);
        $now = $this->now();
        $this->db->execute(
            "INSERT INTO research_results (project_id, workspace_id, kind, subject, status, params, requested_by, requested_at)
             VALUES (?, ?, ?, ?, 'queued', ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = 'queued', params = VALUES(params), error = NULL, requested_by = VALUES(requested_by), requested_at = VALUES(requested_at)",
            [$projectId, $workspaceId, $kind, $subject, json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $userId, $now],
        );
    }

    public function complete(int $projectId, string $kind, string $subject, array $items): void
    {
        $this->db->execute(
            "UPDATE research_results SET status = 'done', items = ?, item_count = ?, error = NULL, fetched_at = ?, params = JSON_REMOVE(params, '$._batch') WHERE project_id = ? AND kind = ? AND subject = ?",
            [json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), count($items), $this->now(), $projectId, $kind, $subject],
        );
    }

    public function fail(int $projectId, string $kind, string $subject, string $error, ?string $requestId = null): void
    {
        $this->db->execute(
            "UPDATE research_results SET status = 'failed', error = ? WHERE project_id = ? AND kind = ? AND subject = ?" . ($requestId !== null ? " AND status = 'queued' AND JSON_UNQUOTE(JSON_EXTRACT(params, '$._request_id')) = ?" : ''),
            $requestId !== null ? [mb_substr($error, 0, 500), $projectId, $kind, $subject, $requestId] : [mb_substr($error, 0, 500), $projectId, $kind, $subject],
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    public function publishRendered(ResearchResult $result, array $items): void
    {
        $this->db->transaction(function () use ($result, $items): void {
            $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM research_results WHERE project_id = ? AND kind = ? AND subject = ? FOR UPDATE', [$result->projectId, $result->kind, $result->subject]);
            $current = $row !== null ? self::result($row) : null;
            $id = $result->params['_request_id'] ?? null;
            if ($result->kind !== 'rendered_audit' || !is_string($id) || !$current?->isQueued() || $current->params != $result->params) {
                throw new \RuntimeException('Rendered request changed before publishing.');
            }
            $params = $result->params;
            unset($params['_batch'], $params['_render_rows'], $params['_render_robots']);
            $this->db->execute('INSERT IGNORE INTO rendered_audit_history (request_id, project_id, params, items, fetched_at) VALUES (?, ?, ?, ?, ?)', [$id, $result->projectId, json_encode($params, JSON_THROW_ON_ERROR), json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $this->now()]);
            $this->complete($result->projectId, $result->kind, $result->subject, $items);
            $this->db->execute('UPDATE research_results SET params = ? WHERE project_id = ? AND kind = ? AND subject = ?', [json_encode($params, JSON_THROW_ON_ERROR), $result->projectId, $result->kind, $result->subject]);
            $this->db->execute('DELETE FROM rendered_audit_history WHERE project_id = ? AND request_id NOT IN (SELECT request_id FROM (SELECT request_id FROM rendered_audit_history WHERE project_id = ? ORDER BY fetched_at DESC, request_id DESC LIMIT 5) retained)', [$result->projectId, $result->projectId]);
        });
    }

    public function renderedHistory(int $projectId): array
    {
        return array_map(static fn(array $row): ResearchResult => self::result([...$row, 'kind' => 'rendered_audit', 'subject' => '', 'status' => 'done', 'error' => null, 'requested_at' => $row['fetched_at']]), $this->db->fetchAll('SELECT project_id, params, items, fetched_at FROM rendered_audit_history WHERE project_id = ? ORDER BY fetched_at DESC, request_id DESC LIMIT 5', [$projectId]));
    }

    /** @param array<string, mixed> $params */
    public function checkpoint(ResearchResult $result, array $params): void
    {
        $this->db->transaction(function () use ($result, $params): void {
            $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM research_results WHERE project_id = ? AND kind = ? AND subject = ? FOR UPDATE', [$result->projectId, $result->kind, $result->subject]);
            $current = $row !== null ? self::result($row) : null;
            if ($current === null || !$current->isQueued() || $current->requestedAt !== $result->requestedAt || $current->params != $result->params) {
                throw new \RuntimeException('The lookup changed while saving progress. No further provider requests were made.');
            }
            $this->db->execute('UPDATE research_results SET params = ? WHERE project_id = ? AND kind = ? AND subject = ?', [json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), $result->projectId, $result->kind, $result->subject]);
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function result(array $row): ResearchResult
    {
        $params = json_decode((string) $row['params'], true);
        $items = is_string($row['items']) ? json_decode($row['items'], true) : [];

        return new ResearchResult(
            (int) $row['project_id'],
            (string) $row['kind'],
            (string) $row['subject'],
            (string) $row['status'],
            is_array($params) ? $params : [],
            is_array($items) ? array_values(array_filter($items, is_array(...))) : [],
            $row['error'] !== null ? (string) $row['error'] : null,
            (string) $row['requested_at'],
            $row['fetched_at'] !== null ? (string) $row['fetched_at'] : null,
        );
    }
}
