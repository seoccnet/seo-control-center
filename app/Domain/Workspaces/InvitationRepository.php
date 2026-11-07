<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Workspaces;

/**
 * Pending invitations to a workspace. Tokens are stored as SHA-256 hashes
 * (binary); "pending" means neither accepted nor revoked and not expired.
 */
interface InvitationRepository
{
    public function create(int $workspaceId, string $email, string $roleKey, string $tokenHash, int $invitedBy, string $expiresAt): Invitation;

    /**
     * @return list<Invitation> pending invitations, newest first
     */
    public function pending(int $workspaceId, string $now): array;

    public function findPendingByTokenHash(string $tokenHash, string $now): ?Invitation;

    /**
     * Marks it accepted; false if it was no longer pending (used twice at once).
     */
    public function markAccepted(int $id, string $now): bool;

    public function revoke(int $workspaceId, string $publicId, string $now): bool;
}
