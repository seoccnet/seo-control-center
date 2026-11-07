<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Quests\QuestProfile;
use SeoControlCenter\Domain\Quests\QuestProgressRepository;

final class InMemoryQuestProgress implements QuestProgressRepository
{
    /** @var array<string, QuestProfile> */
    public array $profiles = [];

    /** @var array<string, array<string, array<string, array{completed: ?string, skipped: ?string}>>> */
    public array $rows = [];

    /** @var array<string, array<int, array{earned: string, announced: bool}>> */
    public array $earned = [];

    public function profile(int $userId, int $workspaceId): QuestProfile
    {
        return $this->profiles[$userId . ':' . $workspaceId] ?? new QuestProfile();
    }

    public function setHidden(int $userId, int $workspaceId, bool $hidden): void
    {
        $p = $this->profile($userId, $workspaceId);
        $this->profiles[$userId . ':' . $workspaceId] = new QuestProfile($hidden, $p->seenQuests, $p->checkedAt);
    }

    public function markSeen(int $userId, int $workspaceId, array $questKeys): void
    {
        $p = $this->profile($userId, $workspaceId);
        $this->profiles[$userId . ':' . $workspaceId] = new QuestProfile($p->hidden, array_values($questKeys), $p->checkedAt);
    }

    public function markChecked(int $userId, int $workspaceId, string $at): void
    {
        $p = $this->profile($userId, $workspaceId);
        $this->profiles[$userId . ':' . $workspaceId] = new QuestProfile($p->hidden, $p->seenQuests, $at);
    }

    public function progress(int $userId, int $workspaceId): array
    {
        return $this->rows[$userId . ':' . $workspaceId] ?? [];
    }

    public function complete(int $userId, int $workspaceId, string $questKey, string $stepKey, string $at): void
    {
        $row = $this->rows[$userId . ':' . $workspaceId][$questKey][$stepKey] ?? ['completed' => null, 'skipped' => null];
        $row['completed'] ??= $at;
        $this->rows[$userId . ':' . $workspaceId][$questKey][$stepKey] = $row;
    }

    public function skip(int $userId, int $workspaceId, string $questKey, string $at): void
    {
        $row = $this->rows[$userId . ':' . $workspaceId][$questKey][''] ?? ['completed' => null, 'skipped' => null];
        $row['skipped'] ??= $at;
        $this->rows[$userId . ':' . $workspaceId][$questKey][''] = $row;
    }

    public function unskip(int $userId, int $workspaceId, string $questKey): void
    {
        if (isset($this->rows[$userId . ':' . $workspaceId][$questKey][''])) {
            $this->rows[$userId . ':' . $workspaceId][$questKey]['']['skipped'] = null;
        }
    }

    public function ranks(int $userId, int $workspaceId): array
    {
        return $this->earned[$userId . ':' . $workspaceId] ?? [];
    }

    public function earnRank(int $userId, int $workspaceId, int $chapter, string $at): void
    {
        $this->earned[$userId . ':' . $workspaceId][$chapter] ??= ['earned' => $at, 'announced' => false];
    }

    public function announceRanks(int $userId, int $workspaceId, array $chapters, string $at): void
    {
        foreach ($chapters as $chapter) {
            if (isset($this->earned[$userId . ':' . $workspaceId][$chapter])) {
                $this->earned[$userId . ':' . $workspaceId][$chapter]['announced'] = true;
            }
        }
    }

    public function due(string $before, int $limit): array
    {
        $due = [];
        foreach ($this->profiles as $key => $profile) {
            if ($profile->checkedAt === null || $profile->checkedAt < $before) {
                [$user, $workspace] = array_map('intval', explode(':', $key));
                $due[] = ['user_id' => $user, 'workspace_id' => $workspace];
            }
        }

        return array_slice($due, 0, $limit);
    }
}
