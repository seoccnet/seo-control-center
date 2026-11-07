<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Accounts;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * System → Users (installation administrators, system.users.manage):
 * deactivate and reactivate users, reset their two-factor sign-in, send a
 * password-reset link, grant and remove administrator rights.
 *
 * Nobody deactivates themselves or removes their own administrator rights,
 * and at least one active administrator always remains. Signing out the
 * sessions of a deactivated user is the caller's job (HTTP layer).
 */
final readonly class UserAdministration
{
    public function __construct(
        private UserRepository $users,
        private MfaRepository $mfa,
        private PasswordResetService $resets,
        private Authorizer $authorizer,
        private AuditLogger $audit,
    ) {}

    /**
     * @return list<UserOverview>
     */
    public function overview(User $actor): array
    {
        $this->authorize($actor);

        return $this->users->overview();
    }

    public function canSendResetLinks(): bool
    {
        return $this->resets->isAvailable();
    }

    /**
     * @throws ValidationException
     */
    public function deactivate(User $actor, string $userPublicId, ClientInfo $client): User
    {
        $target = $this->target($actor, $userPublicId);
        if ($target->id === $actor->id) {
            throw new ValidationException(['user' => 'You cannot deactivate your own account.']);
        }
        if ($target->systemRole === SystemRole::Admin && $target->active && $this->users->countActiveAdmins() <= 1) {
            throw new ValidationException(['user' => 'At least one active installation administrator must remain.']);
        }
        if ($target->active) {
            $this->users->setActive($target->id, false);
            $this->record('user.deactivated', $actor, $target, $client);
        }

        return $target;
    }

    public function activate(User $actor, string $userPublicId, ClientInfo $client): User
    {
        $target = $this->target($actor, $userPublicId);
        if (!$target->active) {
            $this->users->setActive($target->id, true);
            $this->record('user.reactivated', $actor, $target, $client);
        }

        return $target;
    }

    public function resetMfa(User $actor, string $userPublicId, ClientInfo $client): User
    {
        $target = $this->target($actor, $userPublicId);
        $this->mfa->deleteTotp($target->id);
        $this->mfa->replaceRecoveryCodes($target->id, []);
        $this->record('mfa.reset_by_admin', $actor, $target, $client);

        return $target;
    }

    /**
     * @return array{user: User, sent: bool}
     */
    public function sendPasswordReset(User $actor, string $userPublicId, ClientInfo $client): array
    {
        $target = $this->target($actor, $userPublicId);
        $sent = $this->resets->sendLinkTo($target, $client);
        $this->record('user.password_reset_sent', $actor, $target, $client, $sent);

        return ['user' => $target, 'sent' => $sent];
    }

    /**
     * @throws ValidationException
     */
    public function setAdmin(User $actor, string $userPublicId, bool $admin, ClientInfo $client): User
    {
        $target = $this->target($actor, $userPublicId);
        if (!$admin && $target->id === $actor->id) {
            throw new ValidationException(['user' => 'You cannot remove your own administrator rights.']);
        }
        if (!$admin && $target->systemRole === SystemRole::Admin && $target->active && $this->users->countActiveAdmins() <= 1) {
            throw new ValidationException(['user' => 'At least one active installation administrator must remain.']);
        }
        $role = $admin ? SystemRole::Admin : SystemRole::None;
        if ($target->systemRole !== $role) {
            $this->users->setSystemRole($target->id, $role);
            $this->record($admin ? 'user.admin_granted' : 'user.admin_revoked', $actor, $target, $client);
        }

        return $target;
    }

    private function authorize(User $actor): void
    {
        $this->authorizer->authorize($actor->subject(), Permission::SystemUsersManage);
    }

    private function target(User $actor, string $userPublicId): User
    {
        $this->authorize($actor);
        foreach ($this->users->overview() as $row) {
            if ($row->user->publicId === $userPublicId) {
                return $row->user;
            }
        }

        throw new NotFoundHttpException('User not found.');
    }

    private function record(string $action, User $actor, User $target, ClientInfo $client, bool $success = true): void
    {
        $this->audit->record(new AuditEvent(
            $action,
            ActorType::User,
            actorUserId: $actor->id,
            actorLabel: $actor->email,
            targetType: 'user',
            targetId: $target->publicId,
            success: $success,
            metadata: ['email' => $target->email],
        ), $client);
    }
}
