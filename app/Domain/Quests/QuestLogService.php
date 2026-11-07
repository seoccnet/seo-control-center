<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Quests;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Workspaces\Workspace;

/**
 * Evaluates the Quest Log (ADR-035). State-based: each step reads current data
 * through QuestEvidence; there is no event bus. Foundations is evaluated live
 * for the selected project; learning quests are stored the first time they are
 * seen done and stay done. Viewing never queues jobs or calls providers.
 */
final readonly class QuestLogService
{
    public function __construct(
        private QuestProgressRepository $progress,
        private QuestEvidence $evidence,
        private SetupStatusReader $setup,
        private Clock $clock,
    ) {}

    /**
     * @param list<int> $projectIds projects the user can see in the workspace
     * @param bool $acknowledge the caller shows new quests and rank toasts, so they count as seen;
     *                          false for actions that only need the state (they would otherwise be lost)
     */
    public function build(User $user, Workspace $workspace, ?Project $project, array $projectIds, QuestAccess $access, bool $acknowledge = true): QuestLog
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $at = $now->format('Y-m-d H:i:s');
        $scope = EvidenceScope::at($workspace->id, $user->id, $projectIds, $now);
        $setup = $this->setup->check($user, $workspace, $project);
        $stored = $this->progress->progress($user->id, $workspace->id);
        $profile = $this->progress->profile($user->id, $workspace->id);
        $ranks = $this->progress->ranks($user->id, $workspace->id);

        $byChapter = [];
        foreach (QuestCatalogue::quests() as $quest) {
            if ($quest->live) {
                if (isset($setup[$quest->key])) {
                    $byChapter[$quest->chapter][] = new QuestState($quest, [new StepState($quest->steps[0], $setup[$quest->key]->ready())], $setup[$quest->key]);
                }
            } elseif ($access->allows($quest)) {
                $byChapter[$quest->chapter][] = $this->evaluate($quest, $stored[$quest->key] ?? [], $scope, $user->id, $at);
            }
        }

        $chapters = [];
        $announce = [];
        foreach (QuestCatalogue::chapters() as $chapter) {
            $quests = $byChapter[$chapter->number] ?? [];
            $state = $chapter->number === 1 ? $this->foundations($chapter, $quests, $ranks[1]['earned'] ?? null) : $this->learning($chapter, $quests, $ranks[$chapter->number]['earned'] ?? null);
            if ($state->complete() && $state->earned === null) {
                $this->progress->earnRank($user->id, $workspace->id, $chapter->number, $at);
                $state = new ChapterState($chapter, $state->quests, $state->done, $state->total, $at, $state->needsAttention, $state->next);
                $ranks[$chapter->number] = ['earned' => $at, 'announced' => false];
            }
            if (isset($ranks[$chapter->number]) && !$ranks[$chapter->number]['announced']) {
                $announce[] = $chapter;
            }
            $chapters[] = $state;
        }
        if ($acknowledge && $announce !== []) {
            $this->progress->announceRanks($user->id, $workspace->id, array_map(static fn(Chapter $c): int => $c->number, $announce), $at);
        }

        $visible = [];
        foreach ($chapters as $chapter) {
            foreach ($chapter->quests as $quest) {
                $visible[] = $quest->quest->key;
            }
        }
        $newQuests = [];
        if ($profile->seenQuests !== null) {
            foreach (array_diff($visible, $profile->seenQuests) as $key) {
                $quest = QuestCatalogue::find($key);
                if ($quest !== null) {
                    $newQuests[] = $quest;
                }
            }
        }
        if ($acknowledge && ($profile->seenQuests === null || array_diff($visible, $profile->seenQuests) !== [] || array_diff($profile->seenQuests, $visible) !== [])) {
            $this->progress->markSeen($user->id, $workspace->id, $visible);
        }
        if ($profile->checkedAt === null || $profile->checkedAt < $now->modify('-1 hour')->format('Y-m-d H:i:s')) {
            $this->progress->markChecked($user->id, $workspace->id, $at);
        }

        return new QuestLog($chapters, $this->current($chapters), $newQuests, $profile->hidden, $announce);
    }

    /**
     * Background re-check (cron): completes unfinished learning quests so wait
     * steps finish without a visit. Reads only; no provider calls.
     *
     * @param list<int> $projectIds
     */
    public function recheck(int $userId, int $workspaceId, array $projectIds): void
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $at = $now->format('Y-m-d H:i:s');
        $scope = EvidenceScope::at($workspaceId, $userId, $projectIds, $now);
        $stored = $this->progress->progress($userId, $workspaceId);
        foreach (QuestCatalogue::quests() as $quest) {
            $completed = $stored[$quest->key]['']['completed'] ?? null;
            $reopened = $completed !== null && array_filter($quest->steps, static fn(QuestStep $step): bool => !$step->coveredBy($completed) && ($stored[$quest->key][$step->key]['completed'] ?? null) === null) !== [];
            if (!$quest->live && !$quest->weekly && ($completed === null || $reopened)) {
                $this->evaluate($quest, $stored[$quest->key] ?? [], $scope, $userId, $at);
            }
        }
        $this->progress->markChecked($userId, $workspaceId, $at);
    }

    /**
     * @param array<string, array{completed: ?string, skipped: ?string}> $stored
     */
    private function evaluate(Quest $quest, array $stored, EvidenceScope $scope, int $userId, string $at): QuestState
    {
        if ($quest->weekly) {
            $week = $scope->weekKey();
            $done = ($stored[$week]['completed'] ?? null) !== null || $this->any($quest->steps[0], $scope);
            if ($done && ($stored[$week]['completed'] ?? null) === null) {
                $this->progress->complete($userId, $scope->workspaceId, $quest->key, $week, $at);
            }
            $weeks = array_keys(array_filter($stored, static fn(array $row, int|string $key): bool => str_starts_with((string) $key, 'week-') && $row['completed'] !== null, ARRAY_FILTER_USE_BOTH));
            if ($done) {
                $weeks[] = $week;
            }
            rsort($weeks);

            return new QuestState($quest, [new StepState($quest->steps[0], $done)], lastCheckIn: isset($weeks[0]) ? (string) $weeks[0] : null);
        }

        $completed = $stored['']['completed'] ?? null;
        $steps = [];
        $reopened = false;
        foreach ($quest->steps as $step) {
            $done = ($completed !== null && $step->coveredBy($completed)) || ($stored[$step->key]['completed'] ?? null) !== null;
            if (!$done && $this->any($step, $scope)) {
                $this->progress->complete($userId, $scope->workspaceId, $quest->key, $step->key, $at);
                $done = true;
            }
            $steps[] = new StepState($step, $done);
            $reopened = $reopened || !$done;
        }
        if ($completed === null && !$reopened) {
            $this->progress->complete($userId, $scope->workspaceId, $quest->key, '', $at);
            $completed = $at;
        }

        // A step added after the quest was finished reopens it until that step is done; the stored completion stays.
        return new QuestState($quest, $steps, null, $reopened ? null : $completed, $stored['']['skipped'] ?? null);
    }

    private function any(QuestStep $step, EvidenceScope $scope): bool
    {
        foreach ($step->signals as $signal) {
            if ($this->evidence->has($signal, $scope)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<QuestState> $quests */
    private function foundations(Chapter $chapter, array $quests, ?string $earned): ChapterState
    {
        $counted = array_filter($quests, static fn(QuestState $q): bool => $q->setup?->counted() === true);
        $done = count(array_filter($counted, static fn(QuestState $q): bool => $q->done()));
        $next = null;
        foreach ($quests as $quest) {
            if ($quest->setup !== null && !$quest->setup->optional && !$quest->setup->ready()) {
                $next = $quest->quest->key;
                break;
            }
        }
        // Live: a setup step can go backwards. The earned badge stays; the marker shows.
        $attention = array_filter($quests, static fn(QuestState $q): bool => $q->setup?->needsAttention() === true) !== []
            || ($earned !== null && $done < count($counted));

        return new ChapterState($chapter, $quests, $done, count($counted), $earned, $attention, $next);
    }

    /** @param list<QuestState> $quests */
    private function learning(Chapter $chapter, array $quests, ?string $earned): ChapterState
    {
        $counted = array_filter($quests, static fn(QuestState $q): bool => $q->quest->counted());

        return new ChapterState($chapter, $quests, count(array_filter($counted, static fn(QuestState $q): bool => $q->resolved())), count($counted), $earned);
    }

    /**
     * The quest to suggest next: a setup step the user can act on, then the first
     * unfinished learning quest (preferring one that isn't just waiting), then
     * any waiting step, then the Weekly Check-in.
     *
     * @param list<ChapterState> $chapters
     */
    private function current(array $chapters): ?QuestState
    {
        $waiting = null;
        foreach ($chapters as $chapter) {
            foreach ($chapter->quests as $quest) {
                if ($quest->quest->weekly || $quest->resolved()) {
                    continue;
                }
                if ($quest->setup !== null) {
                    if ($quest->setup->optional || !$quest->setup->canAct) {
                        continue;
                    }
                    if ($quest->setup->status === SetupStatus::WAITING) {
                        $waiting ??= $quest;
                        continue;
                    }

                    return $quest;
                }
                if ($quest->status() === QuestState::WAITING) {
                    $waiting ??= $quest;
                    continue;
                }

                return $quest;
            }
        }
        if ($waiting !== null) {
            return $waiting;
        }
        foreach ($chapters as $chapter) {
            foreach ($chapter->quests as $quest) {
                if ($quest->quest->weekly) {
                    return $quest;
                }
            }
        }

        return null;
    }
}
