<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Workspaces\Persistence;

use RuntimeException;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Workspaces\Invitation;
use SeoControlCenter\Domain\Workspaces\InvitationRepository;

final readonly class PdoInvitationRepository implements InvitationRepository
{
    private const string SELECT = 'SELECT i.id, i.public_id, i.workspace_id, w.name AS workspace_name, i.email, i.role_key,
                                          u.name AS invited_by_name, i.created_at, i.expires_at
                                     FROM workspace_invitations i
                                     JOIN workspaces w ON w.id = i.workspace_id
                                     LEFT JOIN users u ON u.id = i.invited_by';
    private const string ACTIVE = ' AND w.deleted_at IS NULL';

    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function create(int $workspaceId, string $email, string $roleKey, string $tokenHash, int $invitedBy, string $expiresAt): Invitation
    {
        $this->db->execute(
            'INSERT INTO workspace_invitations (public_id, workspace_id, email, role_key, token_hash, invited_by, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [Uuid::v7($this->clock), $workspaceId, $email, $roleKey, $tokenHash, $invitedBy, $this->clock->now()->format('Y-m-d H:i:s'), $expiresAt],
        );
        $row = $this->db->fetchOne(self::SELECT . ' WHERE i.id = ?', [$this->db->lastInsertId()]);
        if ($row === null) {
            throw new RuntimeException('The invitation could not be read back.');
        }

        return self::map($row);
    }

    public function pending(int $workspaceId, string $now): array
    {
        $rows = $this->db->fetchAll(
            self::SELECT . ' WHERE i.workspace_id = ? AND i.accepted_at IS NULL AND i.revoked_at IS NULL AND i.expires_at > ?' . self::ACTIVE . '
                             ORDER BY i.created_at DESC, i.id DESC',
            [$workspaceId, $now],
        );

        return array_map(self::map(...), $rows);
    }

    public function findPendingByTokenHash(string $tokenHash, string $now): ?Invitation
    {
        $row = $this->db->fetchOne(
            self::SELECT . ' WHERE i.token_hash = ? AND i.accepted_at IS NULL AND i.revoked_at IS NULL AND i.expires_at > ?' . self::ACTIVE,
            [$tokenHash, $now],
        );

        return $row === null ? null : self::map($row);
    }

    public function markAccepted(int $id, string $now): bool
    {
        return $this->db->execute(
            'UPDATE workspace_invitations SET accepted_at = ? WHERE id = ? AND accepted_at IS NULL AND revoked_at IS NULL',
            [$now, $id],
        ) === 1;
    }

    public function revoke(int $workspaceId, string $publicId, string $now): bool
    {
        return $this->db->execute(
            'UPDATE workspace_invitations SET revoked_at = ?
              WHERE workspace_id = ? AND public_id = ? AND accepted_at IS NULL AND revoked_at IS NULL',
            [$now, $workspaceId, $publicId],
        ) === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function map(array $row): Invitation
    {
        return new Invitation(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['workspace_id'],
            (string) $row['workspace_name'],
            (string) $row['email'],
            (string) $row['role_key'],
            $row['invited_by_name'] === null ? null : (string) $row['invited_by_name'],
            (string) $row['created_at'],
            (string) $row['expires_at'],
        );
    }
}
