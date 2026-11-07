<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Workspaces;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * Workspaces in the browser (1.0):
 * - installation administrators create workspaces and become owner;
 * - owners and admins rename them (workspace.manage);
 * - owners delete them (workspace.delete) after typing the name. Deleting is a soft
 *   delete of the workspace and its projects. The installation's last workspace,
 *   and one the user has no other workspace besides, cannot be deleted.
 */
final readonly class WorkspaceService
{
    public const int MAX_NAME_LENGTH = 120;

    public function __construct(
        private WorkspaceRepository $workspaces,
        private Authorizer $authorizer,
        private AuditLogger $audit,
    ) {}

    public function canCreate(User $actor): bool
    {
        return $this->authorizer->can($actor->subject(), Permission::SystemSettingsManage);
    }

    /**
     * @throws ValidationException
     */
    public function create(User $actor, string $name, ClientInfo $client): Workspace
    {
        $this->authorizer->authorize($actor->subject(), Permission::SystemSettingsManage);
        $name = $this->validName($name);

        $workspace = $this->workspaces->createWithOwner($name, $actor->id);
        $this->record('workspace.created', $actor, $workspace, ['name' => $name], $client);

        return $workspace;
    }

    /**
     * @throws ValidationException
     */
    public function rename(User $actor, Workspace $workspace, string $name, ClientInfo $client): Workspace
    {
        $this->authorizer->authorize($actor->subject(), Permission::WorkspaceManage, $workspace->id);
        $name = $this->validName($name);
        if ($name !== $workspace->name) {
            $this->workspaces->rename($workspace->id, $name);
            $this->record('workspace.renamed', $actor, $workspace, ['from' => $workspace->name, 'to' => $name], $client);
        }

        return new Workspace($workspace->id, $workspace->publicId, $name);
    }

    /**
     * @param list<WorkspaceMembershipView> $actorWorkspaces the actor's current workspaces
     * @throws ValidationException
     */
    public function delete(User $actor, Workspace $workspace, string $confirmation, array $actorWorkspaces, ClientInfo $client): void
    {
        $this->authorizer->authorize($actor->subject(), Permission::WorkspaceDelete, $workspace->id);
        if (trim($confirmation) !== $workspace->name) {
            throw new ValidationException(['confirm' => 'Type the workspace name exactly as shown to confirm.']);
        }
        $problem = $this->deleteProblem($workspace, $actorWorkspaces);
        if ($problem !== null) {
            throw new ValidationException(['confirm' => $problem]);
        }

        $this->workspaces->softDelete($workspace->id);
        $this->record('workspace.deleted', $actor, $workspace, ['name' => $workspace->name], $client);
    }

    /**
     * Why the workspace cannot be deleted at all, or null.
     *
     * @param list<WorkspaceMembershipView> $actorWorkspaces
     */
    public function deleteProblem(Workspace $workspace, array $actorWorkspaces): ?string
    {
        if ($this->workspaces->countAll() <= 1) {
            return 'This is the only workspace of the installation, so it cannot be deleted.';
        }
        $others = array_filter($actorWorkspaces, static fn(WorkspaceMembershipView $m): bool => $m->workspace->id !== $workspace->id);
        if ($others === []) {
            return 'You have no other workspace to continue in. Create or join another workspace first.';
        }

        return null;
    }

    /**
     * @throws ValidationException
     */
    private function validName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new ValidationException(['name' => sprintf('Enter a name of up to %d characters.', self::MAX_NAME_LENGTH)]);
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function record(string $action, User $actor, Workspace $workspace, array $metadata, ClientInfo $client): void
    {
        $this->audit->record(new AuditEvent(
            $action,
            ActorType::User,
            actorUserId: $actor->id,
            actorLabel: $actor->email,
            workspaceId: $workspace->id,
            targetType: 'workspace',
            targetId: $workspace->publicId,
            metadata: $metadata,
        ), $client);
    }
}
