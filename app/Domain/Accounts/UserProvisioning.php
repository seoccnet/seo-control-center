<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Accounts;

use SensitiveParameter;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Security\PasswordHasher;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Domain\Workspaces\Workspace;
use SeoControlCenter\Domain\Workspaces\WorkspaceRepository;

/**
 * Creates user accounts (console now; installer and invitations later).
 */
final readonly class UserProvisioning
{
    public function __construct(
        private UserRepository $users,
        private WorkspaceRepository $workspaces,
        private PasswordHasher $hasher,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array{user: User, workspace: ?Workspace}
     * @throws ValidationException
     */
    public function createUser(
        string $email,
        string $name,
        #[SensitiveParameter]
        string $password,
        bool $systemAdmin,
        ?string $workspaceName,
        ActorType $actor,
        ClientInfo $client,
    ): array {
        $errors = [];
        $emailObject = Email::tryFrom($email);
        if ($emailObject === null) {
            $errors['email'] = 'Enter a valid e-mail address.';
        } elseif ($this->users->findByEmail($emailObject) !== null) {
            $errors['email'] = 'A user with this e-mail address already exists.';
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter a name of up to 120 characters.';
        }
        $passwordErrors = $this->hasher->validate($password);
        if ($passwordErrors !== []) {
            $errors['password'] = implode(' ', $passwordErrors);
        }
        if ($workspaceName !== null && (trim($workspaceName) === '' || mb_strlen($workspaceName) > 120)) {
            $errors['workspace'] = 'Enter a workspace name of up to 120 characters.';
        }
        if ($errors !== [] || $emailObject === null) {
            throw new ValidationException($errors);
        }

        $user = $this->users->create($emailObject, $name, $this->hasher->hash($password), $systemAdmin ? SystemRole::Admin : SystemRole::None);
        $this->audit->record(new AuditEvent('user.created', $actor, targetType: 'user', targetId: $user->publicId, metadata: [
            'email' => $user->email,
            'system_role' => $user->systemRole->value,
        ]), $client);

        $workspace = null;
        if ($workspaceName !== null) {
            $workspace = $this->workspaces->createWithOwner(trim($workspaceName), $user->id);
            $this->audit->record(new AuditEvent('workspace.created', $actor, workspaceId: $workspace->id, targetType: 'workspace', targetId: $workspace->publicId, metadata: [
                'name' => $workspace->name,
                'owner' => $user->email,
            ]), $client);
        }

        return ['user' => $user, 'workspace' => $workspace];
    }
}
