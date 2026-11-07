<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Quests\Jobs;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\LeaseLock;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\UserRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Quests\QuestLogService;
use SeoControlCenter\Domain\Quests\QuestProgressRepository;
use SeoControlCenter\Domain\Workspaces\WorkspaceRepository;

/**
 * Hourly: re-checks unfinished learning quests for the profiles checked least
 * recently, so wait steps (first import, outcome measured, ...) finish without
 * a visit. Bounded per run and idempotent; reads saved data only.
 */
final readonly class RecheckQuestsJob implements JobHandler
{
    public const int BATCH = 50;

    public function __construct(
        private QuestLogService $quests,
        private QuestProgressRepository $progress,
        private UserRepository $users,
        private WorkspaceRepository $workspaces,
        private ProjectService $projects,
        private Connection $db,
        private Clock $clock,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        $lock = new LeaseLock($this->db, 'quests.recheck', 300);
        if (!$lock->acquire()) {
            return;
        }
        try {
            $before = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->modify('-1 hour')->format('Y-m-d H:i:s');
            foreach ($this->progress->due($before, self::BATCH) as $row) {
                if ($context->secondsRemaining() < 10) {
                    break;
                }
                $this->recheck($row['user_id'], $row['workspace_id']);
            }
        } finally {
            $lock->release();
        }
    }

    private function recheck(int $userId, int $workspaceId): void
    {
        $user = $this->users->findById($userId);
        $workspace = null;
        // Only a current, active membership counts; removed members are skipped.
        foreach ($user !== null ? $this->workspaces->forUser($userId) : [] as $membership) {
            if ($membership->workspace->id === $workspaceId) {
                $workspace = $membership->workspace;
            }
        }
        if ($user === null || $workspace === null || !$user->active) {
            $this->progress->markChecked($userId, $workspaceId, $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));

            return;
        }
        $ids = array_map(static fn(\SeoControlCenter\Domain\Projects\Project $p): int => $p->id, $this->projects->visibleProjects($user, $workspace));
        $this->quests->recheck($userId, $workspaceId, $ids);
    }
}
