<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Authorization;

/**
 * Permission catalog (SECURITY.md -> Starter permission catalog).
 * Workspace/project permissions come from roles; system permissions from users.system_role.
 */
enum Permission: string
{
    case WorkspaceView = 'workspace.view';
    case WorkspaceManage = 'workspace.manage';
    case WorkspaceMembersManage = 'workspace.members.manage';
    case WorkspaceDelete = 'workspace.delete';
    case WorkspaceTransfer = 'workspace.transfer';
    case ProjectsView = 'projects.view';
    case ProjectsCreate = 'projects.create';
    case ProjectsEdit = 'projects.edit';
    case ProjectsDelete = 'projects.delete';
    case IntegrationsManage = 'integrations.manage';
    case AnalysisExecute = 'analysis.execute';
    case AuditView = 'audit.view';
    case TokensManage = 'tokens.manage';

    case SystemHealthView = 'system.health.view';
    case SystemUpdate = 'system.update';
    case SystemSettingsManage = 'system.settings.manage';
    case SystemUsersManage = 'system.users.manage';

    public function isSystem(): bool
    {
        return str_starts_with($this->value, 'system.');
    }
}
