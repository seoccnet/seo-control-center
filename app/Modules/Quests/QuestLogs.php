<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Quests;

use SeoControlCenter\Core\Session\Session;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Quests\QuestLog;
use SeoControlCenter\Domain\Quests\QuestLogService;
use SeoControlCenter\Domain\Quests\QuestProgressRepository;
use SeoControlCenter\Domain\Workspaces\Workspace;

/** The Quest Log for a signed-in user: visible projects, project choice and visibility rules. */
final readonly class QuestLogs
{
    public function __construct(
        private QuestLogService $service,
        private ProjectService $projects,
        private QuestAccessFactory $access,
        private QuestProgressRepository $progress,
    ) {}

    /** The user hid the Quest Log card in this workspace. */
    public function hidden(User $user, Workspace $workspace): bool
    {
        return $this->progress->profile($user->id, $workspace->id)->hidden;
    }

    /** @return list<Project> */
    public function projects(User $user, Workspace $workspace): array
    {
        return $this->projects->visibleProjects($user, $workspace);
    }

    /**
     * The project whose setup Foundations shows: the one chosen earlier in this session, else the first.
     *
     * @param list<Project> $projects
     */
    public function selected(Session $session, Workspace $workspace, array $projects): ?Project
    {
        $chosen = $session->get(self::key($workspace));
        foreach ($projects as $project) {
            if ($project->publicId === $chosen) {
                return $project;
            }
        }

        return $projects[0] ?? null;
    }

    /** Resolves a project choice (404 for a foreign or invisible project) and remembers it. */
    public function choose(Session $session, User $user, Workspace $workspace, string $publicId): Project
    {
        $project = $this->projects->get($user, $workspace, $publicId);
        $session->set(self::key($workspace), $project->publicId);

        return $project;
    }

    /** @param list<Project> $projects */
    public function build(User $user, Workspace $workspace, array $projects, ?Project $project, bool $acknowledge = true): QuestLog
    {
        return $this->service->build($user, $workspace, $project, array_map(static fn(Project $p): int => $p->id, $projects), $this->access->for($user, $workspace, $projects), $acknowledge);
    }

    private static function key(Workspace $workspace): string
    {
        return 'setup.project.' . $workspace->publicId;
    }
}
