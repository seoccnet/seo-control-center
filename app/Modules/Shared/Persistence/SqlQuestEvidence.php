<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Shared\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Domain\Quests\EvidenceScope;
use SeoControlCenter\Domain\Quests\QuestEvidenceSource;

/**
 * Base for a module's Quest Log evidence: each signal is one indexed
 * existence query on the module's own tables, bound to the user's workspace
 * and visible projects (ADR-035).
 */
abstract readonly class SqlQuestEvidence implements QuestEvidenceSource
{
    public function __construct(protected Connection $db) {}

    final public function signals(): array
    {
        return array_keys($this->queries());
    }

    final public function has(string $signal, EvidenceScope $scope): bool
    {
        $query = $this->queries()[$signal] ?? null;
        if ($query === null) {
            return false;
        }
        $sql = $query($scope);
        if ($sql === null) {
            return false;
        }

        return (int) $this->db->fetchValue('SELECT EXISTS(' . $sql[0] . ')', $sql[1]) === 1;
    }

    /** ["project_id IN (?, ?)", [1, 2]] for the visible projects; callers check hasProjects(). */
    final protected static function projects(EvidenceScope $scope, string $column = 'project_id'): string
    {
        return $column . ' IN (' . $scope->placeholders() . ')';
    }

    /**
     * Signal => query builder. A builder returns [sql, params] for a SELECT whose
     * existence completes the signal, or null when it can't apply (e.g. no projects).
     *
     * @return array<string, \Closure(EvidenceScope): (array{0: string, 1: list<int|string>}|null)>
     */
    abstract protected function queries(): array;
}
