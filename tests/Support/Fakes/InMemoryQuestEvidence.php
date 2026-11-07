<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Quests\EvidenceScope;
use SeoControlCenter\Domain\Quests\QuestCatalogue;
use SeoControlCenter\Domain\Quests\QuestEvidenceSource;

/**
 * Quest evidence for HTTP tests: a test grants a signal to a user in a
 * workspace (optionally only for one project) and every check is recorded.
 */
final class InMemoryQuestEvidence implements QuestEvidenceSource
{
    /** @var array<string, list<int>|null> "signal:user:workspace" => project ids (null: any) */
    private array $granted = [];

    /** @var list<array{signal: string, scope: EvidenceScope}> */
    public array $checks = [];

    public function grant(string $signal, int $userId, int $workspaceId, ?int $projectId = null): void
    {
        $this->granted[$signal . ':' . $userId . ':' . $workspaceId] = $projectId !== null ? [$projectId] : null;
    }

    public function revoke(string $signal, int $userId, int $workspaceId): void
    {
        unset($this->granted[$signal . ':' . $userId . ':' . $workspaceId]);
    }

    public function signals(): array
    {
        return QuestCatalogue::evidenceSignals();
    }

    public function has(string $signal, EvidenceScope $scope): bool
    {
        $this->checks[] = ['signal' => $signal, 'scope' => $scope];
        $key = $signal . ':' . $scope->userId . ':' . $scope->workspaceId;
        if (!array_key_exists($key, $this->granted)) {
            return false;
        }
        $projects = $this->granted[$key];

        return $projects === null || array_intersect($projects, $scope->projectIds) !== [];
    }
}
