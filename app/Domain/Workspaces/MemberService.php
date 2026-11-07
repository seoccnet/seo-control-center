<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Workspaces;

use SensitiveParameter;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\Mail\Mailer;
use SeoControlCenter\Core\Mail\MailException;
use SeoControlCenter\Core\Mail\Message;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\Email;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Accounts\UserProvisioning;
use SeoControlCenter\Domain\Accounts\UserRepository;
use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * Workspace members: add, invite, change role, remove (workspace.members.manage).
 *
 * An existing user is added directly. Anyone else gets a one-time invitation
 * link (there is no mailer; the inviter shares it). Only the SHA-256 of the
 * link token is stored, and the link is shown once.
 *
 * Rules: "owner" is never assignable here; only owners change or remove
 * owners; nobody changes their own role or removes themselves. Because of
 * this, a workspace always keeps at least one owner.
 */
final readonly class MemberService
{
    public const array ASSIGNABLE_ROLES = ['admin' => 'Admin', 'member' => 'Member', 'viewer' => 'Viewer'];
    public const int INVITATION_DAYS = 7;

    public function __construct(
        private WorkspaceRepository $workspaces,
        private InvitationRepository $invitations,
        private UserRepository $users,
        private UserProvisioning $provisioning,
        private Authorizer $authorizer,
        private AuditLogger $audit,
        private Clock $clock,
        private Mailer $mailer,
        private string $appUrl,
    ) {}

    public function canManage(User $actor, Workspace $workspace): bool
    {
        return $this->authorizer->can($actor->subject(), Permission::WorkspaceMembersManage, $workspace->id);
    }

    /**
     * @return list<Invitation>
     */
    public function pendingInvitations(User $actor, Workspace $workspace): array
    {
        $this->authorizer->authorize($actor->subject(), Permission::WorkspaceMembersManage, $workspace->id);

        return $this->invitations->pending($workspace->id, $this->now());
    }

    /**
     * @param array<string, mixed> $input email, role
     * @return array{member: ?User, invitation: ?Invitation, token: ?string, link: ?string, mailed: bool}
     *         member when an existing user was added; otherwise the invitation, its one-time token and link.
     *         mailed tells whether the person was told by e-mail (when e-mail is set up).
     * @throws ValidationException
     */
    public function invite(User $actor, Workspace $workspace, array $input, ClientInfo $client): array
    {
        $this->authorizer->authorize($actor->subject(), Permission::WorkspaceMembersManage, $workspace->id);

        $errors = [];
        $email = Email::tryFrom(is_string($input['email'] ?? null) ? $input['email'] : '');
        if ($email === null) {
            $errors['email'] = 'Enter a valid e-mail address.';
        }
        $role = is_string($input['role'] ?? null) ? $input['role'] : '';
        if (!isset(self::ASSIGNABLE_ROLES[$role])) {
            $errors['role'] = 'Choose Admin, Member or Viewer.';
        }
        if ($errors !== [] || $email === null) {
            throw new ValidationException($errors);
        }

        $existing = $this->users->findByEmail($email);
        if ($existing !== null) {
            if (!$existing->active) {
                throw new ValidationException(['email' => 'This user account is deactivated.']);
            }
            if (!$this->workspaces->addMember($workspace->id, $existing->id, $role)) {
                throw new ValidationException(['email' => 'This user is already a member of the workspace.']);
            }
            $this->record('workspace.member_added', $actor, $workspace, 'user', $existing->publicId, ['email' => $existing->email, 'role' => $role], $client);
            $mailed = $this->notify($existing->email, $existing->name, sprintf('You were added to %s', $workspace->name), implode("\n", [
                "Hello {$existing->name},",
                '',
                sprintf('%s added you to the workspace "%s" in SEO Control Center as %s.', $actor->name, $workspace->name, self::ASSIGNABLE_ROLES[$role]),
                '',
                'Sign in to open it: ' . rtrim($this->appUrl, '/') . '/login',
                '',
                'SEO Control Center',
            ]));

            return ['member' => $existing, 'invitation' => null, 'token' => null, 'link' => null, 'mailed' => $mailed];
        }

        foreach ($this->invitations->pending($workspace->id, $this->now()) as $pending) {
            if ($pending->email === $email->value) {
                throw new ValidationException(['email' => 'This address already has an open invitation. Revoke it first to create a new link.']);
            }
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $invitation = $this->invitations->create(
            $workspace->id,
            $email->value,
            $role,
            hash('sha256', $token, true),
            $actor->id,
            $this->clock->now()->modify('+' . self::INVITATION_DAYS . ' days')->format('Y-m-d H:i:s'),
        );
        $this->record('workspace.invitation_created', $actor, $workspace, 'invitation', $invitation->publicId, ['email' => $email->value, 'role' => $role], $client);

        $link = rtrim($this->appUrl, '/') . '/invite/' . $token;
        $mailed = $this->notify($email->value, null, sprintf('%s invited you to %s', $actor->name, $workspace->name), implode("\n", [
            'Hello,',
            '',
            sprintf('%s invited you to the workspace "%s" in SEO Control Center as %s.', $actor->name, $workspace->name, self::ASSIGNABLE_ROLES[$role]),
            '',
            sprintf('To accept, open this link within %d days and choose your name and password:', self::INVITATION_DAYS),
            '',
            $link,
            '',
            'If you did not expect this invitation, you can ignore this e-mail.',
            '',
            'SEO Control Center',
        ]));

        return ['member' => null, 'invitation' => $invitation, 'token' => $token, 'link' => $link, 'mailed' => $mailed];
    }

    public function changeRole(User $actor, Workspace $workspace, string $userPublicId, string $role, ClientInfo $client): MemberView
    {
        [$target, $actorRole] = $this->target($actor, $workspace, $userPublicId);
        if (!isset(self::ASSIGNABLE_ROLES[$role])) {
            throw new ValidationException(['role' => 'Choose Admin, Member or Viewer.']);
        }
        $this->guard($actor, $actorRole, $target, 'change the role of');
        if ($target->roleKey !== $role) {
            $this->workspaces->changeRole($workspace->id, $target->userId, $role);
            $this->record('workspace.member_role_changed', $actor, $workspace, 'user', $target->userPublicId, [
                'email' => $target->email,
                'from' => $target->roleKey,
                'to' => $role,
            ], $client);
        }

        return $target;
    }

    public function remove(User $actor, Workspace $workspace, string $userPublicId, ClientInfo $client): MemberView
    {
        [$target, $actorRole] = $this->target($actor, $workspace, $userPublicId);
        $this->guard($actor, $actorRole, $target, 'remove');
        $this->workspaces->removeMember($workspace->id, $target->userId);
        $this->record('workspace.member_removed', $actor, $workspace, 'user', $target->userPublicId, [
            'email' => $target->email,
            'role' => $target->roleKey,
        ], $client);

        return $target;
    }

    public function revokeInvitation(User $actor, Workspace $workspace, string $invitationPublicId, ClientInfo $client): void
    {
        $this->authorizer->authorize($actor->subject(), Permission::WorkspaceMembersManage, $workspace->id);
        if (!$this->invitations->revoke($workspace->id, $invitationPublicId, $this->now())) {
            throw new NotFoundHttpException('Invitation not found.');
        }
        $this->record('workspace.invitation_revoked', $actor, $workspace, 'invitation', $invitationPublicId, [], $client);
    }

    /**
     * The open invitation behind a link token, or null when it is unknown,
     * used, revoked or expired.
     */
    public function findInvitation(string $token): ?Invitation
    {
        return $this->invitations->findPendingByTokenHash(hash('sha256', $token, true), $this->now());
    }

    /**
     * True when the invited address already has an account, which then
     * joins without choosing a password.
     */
    public function hasAccount(Invitation $invitation): bool
    {
        $email = Email::tryFrom($invitation->email);

        return $email !== null && $this->users->findByEmail($email) !== null;
    }

    /**
     * Accepts an invitation: creates the account (name and password) unless
     * one exists for the address, then adds it with the invited role.
     *
     * @return ?User the member, or null when the link is no longer valid
     * @throws ValidationException
     */
    public function accept(string $token, string $name, #[SensitiveParameter] string $password, ClientInfo $client): ?User
    {
        $invitation = $this->findInvitation($token);
        if ($invitation === null) {
            return null;
        }
        $email = new Email($invitation->email);
        $user = $this->users->findByEmail($email);
        if ($user === null) {
            // Validates first, so a rejected password does not use up the invitation.
            $user = $this->provisioning->createUser($email->value, $name, $password, false, null, ActorType::User, $client)['user'];
        } elseif (!$user->active) {
            throw new ValidationException(['email' => 'This user account is deactivated. Ask the workspace owner for help.']);
        }
        if (!$this->invitations->markAccepted($invitation->id, $this->now())) {
            return null;
        }
        $this->workspaces->addMember($invitation->workspaceId, $user->id, $invitation->roleKey);
        $this->audit->record(new AuditEvent(
            'workspace.invitation_accepted',
            ActorType::User,
            actorUserId: $user->id,
            actorLabel: $user->email,
            workspaceId: $invitation->workspaceId,
            targetType: 'invitation',
            targetId: $invitation->publicId,
            metadata: ['email' => $user->email, 'role' => $invitation->roleKey],
        ), $client);

        return $user;
    }

    /**
     * Sends a notification when e-mail is set up. A failure never blocks the
     * invitation: the inviter still sees the link once and can pass it on.
     */
    private function notify(string $to, ?string $name, string $subject, string $body): bool
    {
        if (!$this->mailer->isConfigured()) {
            return false;
        }
        try {
            $this->mailer->send(new Message($to, $subject, $body, $name));

            return true;
        } catch (MailException) {
            return false;
        }
    }

    /**
     * @return array{0: MemberView, 1: string} the target member and the actor's role
     */
    private function target(User $actor, Workspace $workspace, string $userPublicId): array
    {
        $this->authorizer->authorize($actor->subject(), Permission::WorkspaceMembersManage, $workspace->id);
        $target = null;
        $actorRole = '';
        foreach ($this->workspaces->members($workspace->id) as $member) {
            if ($member->userPublicId === $userPublicId) {
                $target = $member;
            }
            if ($member->userId === $actor->id) {
                $actorRole = $member->roleKey;
            }
        }
        if ($target === null) {
            throw new NotFoundHttpException('Member not found.');
        }

        return [$target, $actorRole];
    }

    private function guard(User $actor, string $actorRole, MemberView $target, string $verb): void
    {
        if ($target->userId === $actor->id) {
            throw new ValidationException(['member' => sprintf('You cannot %s yourself.', $verb === 'remove' ? 'remove' : 'change the role of')]);
        }
        if ($target->roleKey === 'owner' && $actorRole !== 'owner') {
            throw new ValidationException(['member' => sprintf('Only an owner can %s an owner.', $verb)]);
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function record(string $action, User $actor, Workspace $workspace, string $targetType, string $targetId, array $metadata, ClientInfo $client): void
    {
        $this->audit->record(new AuditEvent(
            $action,
            ActorType::User,
            actorUserId: $actor->id,
            actorLabel: $actor->email,
            workspaceId: $workspace->id,
            targetType: $targetType,
            targetId: $targetId,
            metadata: $metadata,
        ), $client);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
