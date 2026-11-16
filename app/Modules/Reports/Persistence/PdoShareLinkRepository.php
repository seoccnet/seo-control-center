<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Reports\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Modules\Reports\ShareLink;
use SeoControlCenter\Modules\Reports\ShareLinkRepository;

final readonly class PdoShareLinkRepository implements ShareLinkRepository
{
    private const string SELECT = 'SELECT l.*, p.public_id AS project_public_id FROM report_share_links l
        JOIN projects p ON p.id = l.project_id AND p.workspace_id = l.workspace_id AND p.deleted_at IS NULL
        JOIN workspaces w ON w.id = l.workspace_id AND w.deleted_at IS NULL';

    public function __construct(private Connection $db, private Clock $clock) {}

    public function create(int $workspaceId, int $projectId, string $tokenHash, string $tokenHint, string $label, string $expiresAt, int $userId, string $now): ShareLink
    {
        $publicId = Uuid::v7($this->clock);
        $this->db->execute(
            'INSERT INTO report_share_links (public_id, workspace_id, project_id, token_hash, token_hint, label, created_by, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$publicId, $workspaceId, $projectId, $tokenHash, $tokenHint, $label, $userId, $expiresAt, $now],
        );

        return $this->find($projectId, $publicId) ?? throw new \RuntimeException('The link was not saved.');
    }

    public function byTokenHash(string $tokenHash): ?ShareLink
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE l.token_hash = ?', [$tokenHash]);

        return $row === null ? null : self::hydrate($row);
    }

    public function forProject(int $projectId, int $limit): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAll(self::SELECT . ' WHERE l.project_id = ? ORDER BY l.id DESC LIMIT ' . max(1, $limit), [$projectId]));
    }

    public function find(int $projectId, string $publicId): ?ShareLink
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE l.project_id = ? AND l.public_id = ?', [$projectId, $publicId]);

        return $row === null ? null : self::hydrate($row);
    }

    public function revoke(int $id, string $now): void
    {
        $this->db->execute('UPDATE report_share_links SET revoked_at = COALESCE(revoked_at, ?) WHERE id = ?', [$now, $id]);
    }

    public function recordView(int $id, string $now): void
    {
        $this->db->execute('UPDATE report_share_links SET views = views + 1, last_viewed_at = ? WHERE id = ?', [$now, $id]);
    }

    public function countActive(int $projectId, string $now): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM report_share_links WHERE project_id = ? AND revoked_at IS NULL AND expires_at > ?', [$projectId, $now]);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): ShareLink
    {
        return new ShareLink(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['workspace_id'],
            (int) $row['project_id'],
            (string) $row['project_public_id'],
            (string) $row['token_hint'],
            (string) $row['label'],
            (string) $row['expires_at'],
            $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null,
            (int) $row['views'],
            $row['last_viewed_at'] !== null ? (string) $row['last_viewed_at'] : null,
            (string) $row['created_at'],
        );
    }
}
