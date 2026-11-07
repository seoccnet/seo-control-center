<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Quests;

use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Workspaces\Workspace;

/**
 * Read-only Foundations checks. Never queues work or calls providers.
 */
interface SetupStatusReader
{
    /**
     * The caller supplies only projects the user can see.
     *
     * @return array<string, SetupStatus> keyed by Foundations quest key, in step order;
     *                                    a step for a feature this installation doesn't have is left out
     */
    public function check(User $user, Workspace $workspace, ?Project $project): array;
}
