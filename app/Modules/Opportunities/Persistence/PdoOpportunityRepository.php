<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;

final readonly class PdoOpportunityRepository implements OpportunityRepository
{
    private const string COLUMNS = 'public_id, type, title, explanation, evidence, impact, confidence, effort, priority, status, first_detected_at, last_detected_at, estimated_clicks, raw_estimated_clicks, snoozed_until, subject';

    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    /** @param list<\SeoControlCenter\Domain\Opportunities\Opportunity> $detected
     * @param list<string>|null $coveredTypes
     * @param array<string, array{status: string, date: ?string, notice?: string}> $coverage
     */
    public function sync(int $projectId, array $detected, ?array $coveredTypes = null, array $coverage = []): array
    {
        return $this->db->transaction(function (Connection $db) use ($projectId, $detected, $coveredTypes, $coverage): array {
            $now = $this->now();
            $timezone = $db->fetchValue('SELECT timezone FROM projects WHERE id = ?', [$projectId]);
            $today = $this->clock->now()->setTimezone(new \DateTimeZone(is_string($timezone) ? $timezone : 'UTC'))->format('Y-m-d');
            $db->execute("INSERT INTO opportunity_status_history (opportunity_id, from_status, to_status, changed_by, created_at) SELECT id, 'snoozed', 'open', NULL, ? FROM opportunities WHERE project_id = ? AND status = 'snoozed' AND snoozed_until <= ?", [$now, $projectId, $today]);
            $db->execute("UPDATE opportunities SET status = 'open', snoozed_until = NULL, status_changed_at = ? WHERE project_id = ? AND status = 'snoozed' AND snoozed_until <= ?", [$now, $projectId, $today]);
            $existing = [];
            foreach ($db->fetchAll('SELECT id, type, subject, evidence, LOWER(HEX(subject_hash)) AS h, status FROM opportunities WHERE project_id = ?', [$projectId]) as $row) {
                $existing[$row['type'] . ':' . $row['h']] = ['id' => (int) $row['id'], 'status' => (string) $row['status'], 'type' => (string) $row['type'], 'sample_complete' => \SeoControlCenter\Domain\Opportunities\SignalRequirements::sampleComplete((string) $row['type'], (string) $row['subject'], $coverage), 'sources' => \SeoControlCenter\Domain\Opportunities\SignalRequirements::stored((string) $row['type'], (string) $row['subject'], json_decode((string) $row['evidence'], true))];
            }

            $counts = ['opened' => 0, 'updated' => 0, 'resolved' => 0];
            $seen = [];
            foreach ($detected as $o) {
                if ($coverage !== [] && !\SeoControlCenter\Domain\Opportunities\SignalCoverage::complete($coverage, \SeoControlCenter\Domain\Opportunities\SignalRequirements::stored($o->type->value, $o->subject, $o->evidence, false))) {
                    continue;
                }
                $hash = hash('sha256', $o->subject);
                $key = $o->type->value . ':' . $hash;
                if (isset($seen[$key])) {
                    continue; // a rule reported the same subject twice
                }
                $seen[$key] = true;
                $values = [
                    mb_substr($o->title, 0, 500),
                    $o->explanation,
                    json_encode($o->evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $o->impact,
                    $o->confidence,
                    $o->effort,
                    $o->priority(),
                    $o->estimatedClicks,
                    $o->rawEstimatedClicks,
                ];
                $known = $existing[$key] ?? null;
                if ($coverage !== [] && $known !== null && !\SeoControlCenter\Domain\Opportunities\SignalCoverage::complete($coverage, $known['sources'])) {
                    continue;
                }
                if ($known === null) {
                    $db->execute(
                        "INSERT INTO opportunities (public_id, project_id, type, subject, subject_hash, title, explanation, evidence, impact, confidence, effort, priority,
                                                    estimated_clicks, raw_estimated_clicks, status, first_detected_at, last_detected_at, status_changed_at)
                         VALUES (?, ?, ?, ?, UNHEX(?), ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, ?, ?)",
                        [Uuid::v7($this->clock), $projectId, $o->type->value, mb_substr($o->subject, 0, 2100), $hash, ...$values, $now, $now, $now],
                    );
                    $this->history($db, $db->lastInsertId(), null, StoredOpportunity::OPEN, null);
                    ++$counts['opened'];
                    continue;
                }
                $reopen = $known['status'] === StoredOpportunity::RESOLVED;
                $db->execute(
                    'UPDATE opportunities SET title = ?, explanation = ?, evidence = ?, impact = ?, confidence = ?, effort = ?, priority = ?, estimated_clicks = ?, raw_estimated_clicks = ?, last_detected_at = ?'
                    . ($reopen ? ", status = 'open', status_changed_at = ?" : '') . ' WHERE id = ?',
                    [...$values, $now, ...($reopen ? [$now] : []), $known['id']],
                );
                if ($reopen) {
                    $this->history($db, $known['id'], StoredOpportunity::RESOLVED, StoredOpportunity::OPEN, null);
                }
                ++$counts['updated'];
            }

            foreach ($existing as $key => $known) {
                if (!isset($seen[$key]) && $known['sample_complete'] && $known['status'] === StoredOpportunity::OPEN && ($coverage !== [] ? \SeoControlCenter\Domain\Opportunities\SignalCoverage::complete($coverage, $known['sources']) : ($coveredTypes === null || in_array($known['type'], $coveredTypes, true)))) {
                    $db->execute("UPDATE opportunities SET status = 'resolved', status_changed_at = ? WHERE id = ?", [$now, $known['id']]);
                    $this->history($db, $known['id'], StoredOpportunity::OPEN, StoredOpportunity::RESOLVED, null);
                    ++$counts['resolved'];
                }
            }

            $db->execute(
                'INSERT INTO project_opportunity_runs (project_id, analyzed_at, detected, coverage) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE analyzed_at = VALUES(analyzed_at), detected = VALUES(detected), coverage = VALUES(coverage)',
                [$projectId, $now, count($seen), json_encode($coverage, JSON_THROW_ON_ERROR)],
            );

            return $counts;
        });
    }

    public function list(int $projectId, string $status, ?OpportunityType $type, int $limit, int $offset): array
    {
        $params = [$projectId, $status];
        if ($type !== null) {
            $params[] = $type->value;
        }

        return array_map(self::opportunity(...), $this->db->fetchAll(
            sprintf(
                'SELECT ' . self::COLUMNS . ' FROM opportunities WHERE project_id = ? AND status = ?%s ORDER BY priority DESC, id LIMIT %d OFFSET %d',
                $type !== null ? ' AND type = ?' : '',
                max(1, $limit),
                max(0, $offset),
            ),
            $params,
        ));
    }

    public function catalog(int $projectId): array
    {
        $rows = $this->db->fetchAll('SELECT ' . self::COLUMNS . ' FROM opportunities WHERE project_id = ? ORDER BY priority DESC, public_id LIMIT 5000', [$projectId]);
        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM opportunities WHERE project_id = ?', [$projectId]);
        return ['rows' => array_map(self::opportunity(...), $rows), 'total' => $total];
    }

    public function count(int $projectId, string $status, ?OpportunityType $type = null): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM opportunities WHERE project_id = ? AND status = ?' . ($type !== null ? ' AND type = ?' : ''),
            $type !== null ? [$projectId, $status, $type->value] : [$projectId, $status],
        );
    }

    public function openByType(int $projectId): array
    {
        $counts = [];
        foreach ($this->db->fetchAll("SELECT type, COUNT(*) AS n FROM opportunities WHERE project_id = ? AND status = 'open' GROUP BY type", [$projectId]) as $row) {
            $counts[(string) $row['type']] = (int) $row['n'];
        }

        return $counts;
    }

    public function find(int $projectId, string $publicId): ?StoredOpportunity
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM opportunities WHERE project_id = ? AND public_id = ?', [$projectId, $publicId]);

        return $row === null ? null : self::opportunity($row);
    }

    public function setStatus(int $projectId, string $publicId, string $status, int $userId): bool
    {
        if (!in_array($status, StoredOpportunity::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown opportunity status.');
        }

        return $this->db->transaction(function (Connection $db) use ($projectId, $publicId, $status, $userId): bool {
            $row = $db->fetchOne('SELECT id, status FROM opportunities WHERE project_id = ? AND public_id = ? FOR UPDATE', [$projectId, $publicId]);
            if ($row === null) {
                return false;
            }
            if ($row['status'] !== $status) {
                $db->execute('UPDATE opportunities SET status = ?, snoozed_until = NULL, status_changed_at = ? WHERE id = ?', [$status, $this->now(), (int) $row['id']]);
                $this->history($db, (int) $row['id'], (string) $row['status'], $status, $userId);
            }

            return true;
        });
    }

    public function snooze(int $projectId, string $publicId, string $until, int $userId): bool
    {
        return $this->db->transaction(function (Connection $db) use ($projectId, $publicId, $until, $userId): bool {
            $row = $db->fetchOne('SELECT id, status FROM opportunities WHERE project_id = ? AND public_id = ? FOR UPDATE', [$projectId, $publicId]);
            if ($row === null) {
                return false;
            }
            $db->execute("UPDATE opportunities SET status = 'snoozed', snoozed_until = ?, status_changed_at = ? WHERE id = ?", [$until, $this->now(), (int) $row['id']]);
            if ($row['status'] !== StoredOpportunity::SNOOZED) {
                $this->history($db, (int) $row['id'], (string) $row['status'], StoredOpportunity::SNOOZED, $userId);
            }
            return true;
        });
    }

    public function coverage(int $projectId): array
    {
        $decoded = json_decode((string) ($this->db->fetchValue('SELECT coverage FROM project_opportunity_runs WHERE project_id = ?', [$projectId]) ?? '{}'), true);
        $result = [];
        foreach (is_array($decoded) ? $decoded : [] as $source => $item) {
            if (is_string($source) && is_array($item) && in_array($item['status'] ?? null, ['available', 'stale', 'missing'], true) && (is_string($item['date'] ?? null) || ($item['date'] ?? null) === null)) {
                $result[$source] = ['status' => (string) $item['status'], 'date' => $item['date'] ?? null, ...(is_string($item['notice'] ?? null) ? ['notice' => $item['notice']] : [])];
            }
        }
        return $result;
    }

    public function lastAnalyzedAt(int $projectId): ?string
    {
        $value = $this->db->fetchValue('SELECT analyzed_at FROM project_opportunity_runs WHERE project_id = ?', [$projectId]);

        return is_string($value) ? $value : null;
    }

    public function projectsToAnalyze(): array
    {
        return array_map(
            static fn(array $r): array => ['project_id' => (int) $r['id'], 'workspace_id' => (int) $r['workspace_id']],
            $this->db->fetchAll('SELECT id, workspace_id FROM projects WHERE deleted_at IS NULL ORDER BY id'),
        );
    }

    private function history(Connection $db, int $opportunityId, ?string $from, string $to, ?int $userId): void
    {
        $db->execute(
            'INSERT INTO opportunity_status_history (opportunity_id, from_status, to_status, changed_by, created_at) VALUES (?, ?, ?, ?, ?)',
            [$opportunityId, $from, $to, $userId, $this->now()],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function opportunity(array $row): StoredOpportunity
    {
        $evidence = [];
        $decoded = json_decode((string) $row['evidence'], true);
        foreach (is_array($decoded) ? $decoded : [] as $item) {
            if (is_array($item) && is_string($item['label'] ?? null) && is_string($item['value'] ?? null)) {
                $evidence[] = ['label' => $item['label'], 'value' => $item['value']];
            }
        }

        return new StoredOpportunity(
            (string) $row['public_id'],
            OpportunityType::from((string) $row['type']),
            (string) $row['title'],
            (string) $row['explanation'],
            $evidence,
            (int) $row['impact'],
            (int) $row['confidence'],
            (int) $row['effort'],
            (float) $row['priority'],
            (string) $row['status'],
            (string) $row['first_detected_at'],
            (string) $row['last_detected_at'],
            $row['estimated_clicks'] !== null ? (float) $row['estimated_clicks'] : null,
            $row['raw_estimated_clicks'] !== null ? (float) $row['raw_estimated_clicks'] : null,
            is_string($row['snoozed_until']) ? $row['snoozed_until'] : null,
            (string) ($row['subject'] ?? ''),
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
