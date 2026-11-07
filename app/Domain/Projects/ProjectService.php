<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Projects;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Domain\Workspaces\Workspace;

/**
 * Application service for projects: authorization, tenant scoping,
 * persistence and audit in one place, so controllers, MCP tools and jobs
 * behave identically.
 */
final readonly class ProjectService
{
    public function __construct(
        private ProjectRepository $projects,
        private Authorizer $authorizer,
        private AuditLogger $audit,
    ) {}

    /**
     * Projects the user may see in the workspace.
     *
     * @return list<Project>
     */
    public function visibleProjects(User $user, Workspace $workspace): array
    {
        $membership = $this->authorizer->workspaceMembership($user->id, $workspace->id);
        if ($membership === null || !$this->authorizer->can($user->subject(), Permission::ProjectsView, $workspace->id)) {
            return [];
        }

        return $membership->coversAllProjects()
            ? $this->projects->allInWorkspace($workspace->id)
            : $this->projects->memberProjectsInWorkspace($workspace->id, $user->id);
    }

    /**
     * Loads a project the user may see. Unknown, foreign-workspace and
     * invisible projects all produce the same 404 so their existence is not revealed.
     */
    public function get(User $user, Workspace $workspace, string $publicId): Project
    {
        $project = $this->projects->findInWorkspace($workspace->id, $publicId);
        if ($project === null || !$this->authorizer->canSeeProject($user->subject(), $workspace->id, $project->id)) {
            throw new NotFoundHttpException('Project not found.');
        }

        return $project;
    }

    public function canCreate(User $user, Workspace $workspace): bool
    {
        return $this->authorizer->can($user->subject(), Permission::ProjectsCreate, $workspace->id);
    }

    public function can(User $user, Permission $permission, Project $project): bool
    {
        return $this->authorizer->can($user->subject(), $permission, $project->workspaceId, $project->id);
    }

    /**
     * @param array<array-key, mixed> $data
     * @throws ValidationException
     */
    public function create(User $user, Workspace $workspace, array $data, ClientInfo $client): Project
    {
        $this->authorizer->authorize($user->subject(), Permission::ProjectsCreate, $workspace->id);
        $input = ProjectInput::fromArray($data);

        $project = $this->projects->create($workspace->id, $input, $user->id);

        $this->audit->record(new AuditEvent(
            'project.created',
            ActorType::User,
            actorUserId: $user->id,
            actorLabel: $user->email,
            workspaceId: $workspace->id,
            targetType: 'project',
            targetId: $project->publicId,
            metadata: ['name' => $project->name, 'domain' => $project->primaryDomain],
        ), $client);

        return $project;
    }

    /**
     * @param array<array-key, mixed> $data
     * @throws ValidationException
     */
    public function update(User $user, Project $project, array $data, ClientInfo $client): Project
    {
        $this->authorizer->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $input = ProjectInput::fromArray($data);

        $updated = $this->projects->update($project, $input);

        $changes = [];
        foreach (['name', 'primaryDomain', 'timezone'] as $field) {
            if ($project->{$field} !== $updated->{$field}) {
                $changes[$field] = ['from' => $project->{$field}, 'to' => $updated->{$field}];
            }
        }
        $this->audit->record(new AuditEvent(
            'project.updated',
            ActorType::User,
            actorUserId: $user->id,
            actorLabel: $user->email,
            workspaceId: $project->workspaceId,
            targetType: 'project',
            targetId: $project->publicId,
            metadata: ['changes' => $changes],
        ), $client);

        return $updated;
    }

    public function delete(User $user, Project $project, ClientInfo $client): void
    {
        $this->authorizer->authorize($user->subject(), Permission::ProjectsDelete, $project->workspaceId, $project->id);

        $this->projects->softDelete($project);

        $this->audit->record(new AuditEvent(
            'project.deleted',
            ActorType::User,
            actorUserId: $user->id,
            actorLabel: $user->email,
            workspaceId: $project->workspaceId,
            targetType: 'project',
            targetId: $project->publicId,
            metadata: ['name' => $project->name],
        ), $client);
    }
}
