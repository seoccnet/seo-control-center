<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Workspaces\Invitation;
use SeoControlCenter\Domain\Workspaces\InvitationRepository;

final class InMemoryInvitations implements InvitationRepository
{
    /** @var array<int, array{invitation: Invitation, hash: string, accepted: ?string, revoked: ?string}> */
    public array $rows = [];

    public function __construct(
        private readonly InMemoryDirectory $directory,
        private readonly Clock $clock,
    ) {}

    public function create(int $workspaceId, string $email, string $roleKey, string $tokenHash, int $invitedBy, string $expiresAt): Invitation
    {
        $id = count($this->rows) + 1;
        $invitation = new Invitation(
            $id,
            Uuid::v7($this->clock),
            $workspaceId,
            $this->directory->workspaces[$workspaceId]->name ?? '',
            $email,
            $roleKey,
            isset($this->directory->users[$invitedBy]) ? $this->directory->users[$invitedBy]['user']->name : null,
            $this->clock->now()->format('Y-m-d H:i:s'),
            $expiresAt,
        );
        $this->rows[$id] = ['invitation' => $invitation, 'hash' => $tokenHash, 'accepted' => null, 'revoked' => null];

        return $invitation;
    }

    public function pending(int $workspaceId, string $now): array
    {
        $result = [];
        foreach (array_reverse($this->rows, true) as $row) {
            if ($row['invitation']->workspaceId === $workspaceId && $this->isPending($row, $now)) {
                $result[] = $row['invitation'];
            }
        }

        return $result;
    }

    public function findPendingByTokenHash(string $tokenHash, string $now): ?Invitation
    {
        foreach ($this->rows as $row) {
            if (hash_equals($row['hash'], $tokenHash) && $this->isPending($row, $now)) {
                return $row['invitation'];
            }
        }

        return null;
    }

    public function markAccepted(int $id, string $now): bool
    {
        if (!isset($this->rows[$id]) || $this->rows[$id]['accepted'] !== null || $this->rows[$id]['revoked'] !== null) {
            return false;
        }
        $this->rows[$id]['accepted'] = $now;

        return true;
    }

    public function revoke(int $workspaceId, string $publicId, string $now): bool
    {
        foreach ($this->rows as $id => $row) {
            if ($row['invitation']->workspaceId === $workspaceId && $row['invitation']->publicId === $publicId
                && $row['accepted'] === null && $row['revoked'] === null) {
                $this->rows[$id]['revoked'] = $now;

                return true;
            }
        }

        return false;
    }

    /**
     * @param array{invitation: Invitation, hash: string, accepted: ?string, revoked: ?string} $row
     */
    private function isPending(array $row, string $now): bool
    {
        return $row['accepted'] === null && $row['revoked'] === null && $row['invitation']->expiresAt > $now
            && !isset($this->directory->deletedWorkspaces[$row['invitation']->workspaceId]);
    }
}
