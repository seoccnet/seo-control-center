<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Analytics\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Analytics\BusinessEvent;
use SeoControlCenter\Domain\Analytics\Ga4EventReport;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Analytics\BusinessEventRepository;

final readonly class PdoBusinessEventRepository implements BusinessEventRepository
{
    public function __construct(private Connection $db, private Clock $clock) {}

    public function events(int $projectId): array
    {
        return array_map(
            static fn(array $r): BusinessEvent => new BusinessEvent((string) $r['public_id'], (int) $r['project_id'], (string) $r['kind'], (string) $r['event_name'], (string) $r['label'], (string) $r['created_at']),
            $this->db->fetchAll('SELECT public_id, project_id, kind, event_name, label, created_at FROM business_events WHERE project_id = ? ORDER BY id', [$projectId]),
        );
    }

    public function add(int $workspaceId, int $projectId, string $kind, string $eventName, string $label, ?int $userId): BusinessEvent
    {
        $id = Uuid::v7($this->clock);
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->db->execute('INSERT INTO business_events (public_id, workspace_id, project_id, kind, event_name, label, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$id, $workspaceId, $projectId, $kind, $eventName, $label, $userId, $now]);

        return new BusinessEvent($id, $projectId, $kind, $eventName, $label, $now);
    }

    public function remove(int $projectId, string $publicId): bool
    {
        return (bool) $this->db->transaction(function () use ($projectId, $publicId): bool {
            $name = $this->db->fetchValue('SELECT event_name FROM business_events WHERE project_id = ? AND public_id = ? FOR UPDATE', [$projectId, $publicId]);
            if (!is_string($name)) {
                return false;
            }
            $this->db->execute('DELETE FROM business_events WHERE project_id = ? AND public_id = ?', [$projectId, $publicId]);
            $this->db->execute('DELETE FROM ga4_event_days WHERE project_id = ? AND event_name = ?', [$projectId, $name]);

            return true;
        });
    }

    public function saveEventReport(int $projectId, DateRange $range, Ga4EventReport $report): void
    {
        $this->db->transaction(function () use ($projectId, $range, $report): void {
            foreach (array_chunk($report->rows, 200) as $chunk) {
                $params = [];
                foreach ($chunk as $row) {
                    array_push($params, $projectId, $row['date'], $row['event_name'], $row['organic'], $row['total']);
                }
                $this->db->execute('INSERT INTO ga4_event_days (project_id, date, event_name, organic_count, total_count) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?)'))
                    . ' ON DUPLICATE KEY UPDATE organic_count = VALUES(organic_count), total_count = VALUES(total_count)', $params);
            }
            $this->db->execute(
                'INSERT INTO ga4_event_imports (project_id, period_from, period_to, timezone, limited, thresholded, fetched_at, error, error_at) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL)
                ON DUPLICATE KEY UPDATE period_from = VALUES(period_from), period_to = VALUES(period_to), timezone = VALUES(timezone), limited = VALUES(limited), thresholded = VALUES(thresholded), fetched_at = VALUES(fetched_at), error = NULL, error_at = NULL',
                [$projectId, $range->fromString(), $range->toString(), mb_substr($report->timezone, 0, 64), (int) $report->limited, (int) $report->thresholded, $this->clock->now()->format('Y-m-d H:i:s')]
            );
        });
    }

    public function recordEventFailure(int $projectId, string $error): void
    {
        $this->db->execute(
            'INSERT INTO ga4_event_imports (project_id, error, error_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE error = VALUES(error), error_at = VALUES(error_at)',
            [$projectId, mb_substr($error, 0, 500), $this->clock->now()->format('Y-m-d H:i:s')]
        );
    }

    public function eventImport(int $projectId): ?array
    {
        $r = $this->db->fetchOne('SELECT period_from, period_to, timezone, limited, thresholded, fetched_at, error, error_at FROM ga4_event_imports WHERE project_id = ?', [$projectId]);

        return $r === null ? null : [
            'from' => $r['period_from'] !== null ? (string) $r['period_from'] : null,
            'to' => $r['period_to'] !== null ? (string) $r['period_to'] : null,
            'timezone' => $r['timezone'] !== null ? (string) $r['timezone'] : null,
            'limited' => (bool) $r['limited'],
            'thresholded' => (bool) $r['thresholded'],
            'fetched_at' => $r['fetched_at'] !== null ? (string) $r['fetched_at'] : null,
            'error' => $r['error'] !== null ? (string) $r['error'] : null,
            'error_at' => $r['error_at'] !== null ? (string) $r['error_at'] : null,
        ];
    }

    public function counts(int $projectId, DateRange $range): array
    {
        $counts = [];
        foreach ($this->db->fetchAll('SELECT event_name, SUM(organic_count) AS organic, SUM(total_count) AS total, COUNT(*) AS days FROM ga4_event_days WHERE project_id = ? AND date BETWEEN ? AND ? GROUP BY event_name', [$projectId, $range->fromString(), $range->toString()]) as $r) {
            $counts[(string) $r['event_name']] = ['organic' => (int) $r['organic'], 'total' => (int) $r['total'], 'days' => (int) $r['days']];
        }

        return $counts;
    }

    public function addCheck(int $workspaceId, int $projectId, string $note, ?int $userId): string
    {
        $id = Uuid::v7($this->clock);
        $this->db->execute(
            'INSERT INTO business_event_checks (public_id, workspace_id, project_id, status, note, requested_by, requested_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $workspaceId, $projectId, 'queued', mb_substr($note, 0, 500), $userId, $this->clock->now()->format('Y-m-d H:i:s')]
        );

        return $id;
    }

    public function check(string $publicId): ?array
    {
        $r = $this->db->fetchOne(self::CHECK . ' WHERE public_id = ?', [$publicId]);

        return $r === null ? null : self::checkRow($r);
    }

    public function finishCheck(string $publicId, ?array $result, ?string $error): void
    {
        $this->db->execute('UPDATE business_event_checks SET status = ?, result = ?, error = ?, finished_at = ? WHERE public_id = ?', [
            $error === null ? 'done' : 'failed',
            $result !== null ? json_encode($result, JSON_THROW_ON_ERROR) : null,
            $error !== null ? mb_substr($error, 0, 500) : null,
            $this->clock->now()->format('Y-m-d H:i:s'),
            $publicId,
        ]);
    }

    public function checks(int $projectId, int $limit = 5): array
    {
        return array_map(self::checkRow(...), $this->db->fetchAll(self::CHECK . ' WHERE project_id = ? ORDER BY requested_at DESC, id DESC LIMIT ' . max(1, min(50, $limit)), [$projectId]));
    }

    private const string CHECK = 'SELECT public_id, project_id, workspace_id, status, note, result, error, requested_at, finished_at FROM business_event_checks';

    /**
     * @param array<string, mixed> $r
     * @return array{id: string, project_id: int, workspace_id: int, status: string, note: string, result: ?array<string, int>, error: ?string, requested_at: string, finished_at: ?string}
     */
    private static function checkRow(array $r): array
    {
        $result = is_string($r['result'] ?? null) ? json_decode($r['result'], true) : null;

        return [
            'id' => (string) $r['public_id'],
            'project_id' => (int) $r['project_id'],
            'workspace_id' => (int) $r['workspace_id'],
            'status' => (string) $r['status'],
            'note' => (string) $r['note'],
            'result' => is_array($result) ? array_map(static fn(mixed $v): int => is_int($v) ? $v : 0, array_filter($result, static fn(mixed $k): bool => is_string($k), ARRAY_FILTER_USE_KEY)) : null,
            'error' => $r['error'] !== null ? (string) $r['error'] : null,
            'requested_at' => (string) $r['requested_at'],
            'finished_at' => $r['finished_at'] !== null ? (string) $r['finished_at'] : null,
        ];
    }
}
