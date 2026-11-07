<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Quests;

use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Quests\PermissionScope;
use SeoControlCenter\Domain\Quests\QuestAccess;
use SeoControlCenter\Domain\Workspaces\Workspace;
use SeoControlCenter\Modules\Assistant\AssistantService;

/** Builds the visibility rules for one user: FeatureManager, Authorizer and assistant availability only. */
final readonly class QuestAccessFactory
{
    public function __construct(
        private FeatureManager $features,
        private Authorizer $authorizer,
        private ProjectService $projects,
        private AssistantService $assistant,
    ) {}

    /** Whether "Ask AI about this quest" can open the existing assistant. */
    public function assistant(User $user, Workspace $workspace): bool
    {
        return $this->assistant->available($user, $workspace->id);
    }

    /** @param list<Project> $projects the user's visible projects */
    public function for(User $user, Workspace $workspace, array $projects): QuestAccess
    {
        return new QuestAccess(
            fn(Feature $feature): bool => $this->features->enabled($feature),
            function (Permission $permission, PermissionScope $scope) use ($user, $workspace, $projects): bool {
                if ($scope === PermissionScope::Workspace) {
                    return $this->authorizer->can($user->subject(), $permission, $workspace->id);
                }
                foreach ($projects as $project) {
                    if ($this->projects->can($user, $permission, $project)) {
                        return true;
                    }
                }

                return false;
            },
            $this->assistant->available($user, $workspace->id),
        );
    }
}
