<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ContentBriefs\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Claims\Claim;
use SeoControlCenter\Modules\ContentBriefs\ClaimRepository;

/** @phpstan-import-type ClaimData from ClaimRepository */
final readonly class PdoClaimRepository implements ClaimRepository
{
    private const string COLUMNS = 'id, public_id, brief_public_id, claim, subject, claim_scope, source_url, publisher, checked_on, passage, source_scope, status, note, reviewed_by, reviewed_at, version, updated_at';

    public function __construct(private Connection $db, private Clock $clock) {}

    public function forBrief(int $projectId, string $briefId): array
    {
        return array_map(self::claim(...), $this->db->fetchAll('SELECT ' . self::COLUMNS . ' FROM brief_claims WHERE project_id = ? AND brief_public_id = ? ORDER BY id LIMIT ' . Claim::MAX_PER_BRIEF, [$projectId, $briefId]));
    }

    public function forProject(int $projectId, string $status, int $offset, int $limit): array
    {
        $where = 'project_id = ?' . ($status !== '' ? ' AND status = ?' : '');
        $params = $status !== '' ? [$projectId, $status] : [$projectId];

        return [
            'items' => array_map(self::claim(...), $this->db->fetchAll('SELECT ' . self::COLUMNS . ' FROM brief_claims WHERE ' . $where . ' ORDER BY updated_at DESC, id DESC LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset), $params)),
            'total' => (int) $this->db->fetchValue('SELECT COUNT(*) FROM brief_claims WHERE ' . $where, $params),
        ];
    }

    public function find(int $projectId, string $publicId): ?Claim
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM brief_claims WHERE project_id = ? AND public_id = ?', [$projectId, $publicId]);

        return $row === null ? null : self::claim($row);
    }

    public function countForBrief(int $projectId, string $briefId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM brief_claims WHERE project_id = ? AND brief_public_id = ?', [$projectId, $briefId]);
    }

    public function add(int $workspaceId, int $projectId, string $briefId, array $data, ?int $userId): Claim
    {
        $id = Uuid::v7($this->clock);
        $now = $this->now();
        $this->db->execute('INSERT INTO brief_claims (public_id, workspace_id, project_id, brief_public_id, claim, subject, claim_scope, source_url, publisher, checked_on, passage, source_scope, status, note, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $id, $workspaceId, $projectId, $briefId, $data['claim'], $data['subject'], self::json($data['claim_scope']), $data['source_url'], $data['publisher'], $data['checked_on'], $data['passage'], self::json($data['source_scope']), $data['status'], $data['note'], $userId, $now, $now,
        ]);

        return new Claim($id, $briefId, $data['claim'], $data['subject'], $data['claim_scope'], $data['source_url'], $data['publisher'], $data['checked_on'], $data['passage'], $data['source_scope'], $data['status'], $data['note'], null, null, 1, $now);
    }

    public function update(int $projectId, string $publicId, int $version, array $data, bool $reviewed, ?int $userId): bool
    {
        return (bool) $this->db->transaction(function () use ($projectId, $publicId, $version, $data, $reviewed, $userId): bool {
            $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM brief_claims WHERE project_id = ? AND public_id = ? FOR UPDATE', [$projectId, $publicId]);
            if ($row === null || (int) $row['version'] !== $version) {
                return false;
            }
            $now = $this->now();
            $previous = self::claim($row);
            $this->db->execute('INSERT INTO brief_claim_revisions (claim_id, version, snapshot, changed_by, changed_at) VALUES (?, ?, ?, ?, ?)', [(int) $row['id'], $version, json_encode($previous->present(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $userId, $now]);
            $this->db->execute('UPDATE brief_claims SET claim = ?, subject = ?, claim_scope = ?, source_url = ?, publisher = ?, checked_on = ?, passage = ?, source_scope = ?, status = ?, note = ?, reviewed_by = ' . ($reviewed ? '?' : 'reviewed_by') . ', reviewed_at = ' . ($reviewed ? '?' : 'reviewed_at') . ', version = version + 1, updated_at = ? WHERE id = ?', [
                $data['claim'], $data['subject'], self::json($data['claim_scope']), $data['source_url'], $data['publisher'], $data['checked_on'], $data['passage'], self::json($data['source_scope']), $data['status'], $data['note'], ...($reviewed ? [$userId, $now] : []), $now, (int) $row['id'],
            ]);

            return true;
        });
    }

    public function revisions(int $projectId, string $publicId): array
    {
        return array_map(static function (array $r): array {
            $snapshot = json_decode((string) $r['snapshot'], true);

            return ['version' => (int) $r['version'], 'snapshot' => is_array($snapshot) ? $snapshot : [], 'changed_by' => $r['changed_by'] !== null ? (int) $r['changed_by'] : null, 'changed_at' => (string) $r['changed_at']];
        }, $this->db->fetchAll('SELECT r.version, r.snapshot, r.changed_by, r.changed_at FROM brief_claim_revisions r JOIN brief_claims c ON c.id = r.claim_id WHERE c.project_id = ? AND c.public_id = ? ORDER BY r.version DESC LIMIT 50', [$projectId, $publicId]));
    }

    public function remove(int $projectId, string $publicId): bool
    {
        return $this->db->execute('DELETE FROM brief_claims WHERE project_id = ? AND public_id = ?', [$projectId, $publicId]) === 1;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /** @param array<string, string> $scope */
    private static function json(array $scope): string
    {
        return json_encode((object) $scope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, string> */
    private static function scope(mixed $json): array
    {
        $data = is_string($json) ? json_decode($json, true) : null;
        $scope = [];
        foreach (is_array($data) ? $data : [] as $key => $value) {
            if (is_string($key) && isset(Claim::SCOPE[$key]) && is_string($value) && $value !== '') {
                $scope[$key] = $value;
            }
        }

        return $scope;
    }

    /** @param array<string, mixed> $r */
    private static function claim(array $r): Claim
    {
        return new Claim(
            (string) $r['public_id'],
            (string) $r['brief_public_id'],
            (string) $r['claim'],
            (string) $r['subject'],
            self::scope($r['claim_scope']),
            $r['source_url'] !== null ? (string) $r['source_url'] : null,
            (string) $r['publisher'],
            $r['checked_on'] !== null ? (string) $r['checked_on'] : null,
            (string) $r['passage'],
            self::scope($r['source_scope']),
            (string) $r['status'],
            (string) $r['note'],
            $r['reviewed_by'] !== null ? (int) $r['reviewed_by'] : null,
            $r['reviewed_at'] !== null ? (string) $r['reviewed_at'] : null,
            (int) $r['version'],
            (string) $r['updated_at'],
        );
    }
}
