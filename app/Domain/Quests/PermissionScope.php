<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Quests;

/** Where a quest's required permission is checked: the workspace, or any project the user can see. */
enum PermissionScope: string
{
    case Workspace = 'workspace';
    case Project = 'project';
}
