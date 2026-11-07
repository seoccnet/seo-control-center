<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities\Tasks;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Projects\Project;

final readonly class PdoTaskRepository implements TaskRepository
{
    private const string JOIN = ' FROM opportunity_tasks t JOIN opportunities o ON o.id = t.opportunity_id AND o.project_id = t.project_id JOIN projects p ON p.id = t.project_id AND p.workspace_id = t.workspace_id AND p.deleted_at IS NULL JOIN workspaces w ON w.id = t.workspace_id AND w.deleted_at IS NULL';
    public function __construct(private Connection $db, private Clock $clock) {}
    public function find(Project $project, string $opportunityId): ?SeoTask
    {
        $row = $this->db->fetchOne('SELECT t.*, o.public_id, o.title, o.priority, o.status AS finding_status' . self::JOIN . ' WHERE t.workspace_id = ? AND t.project_id = ? AND o.public_id = ?', [$project->workspaceId, $project->id, $opportunityId]);
        return $row === null ? null : $this->hydrate($row);
    }
    public function list(Project $project, TaskFilter $filter, string $today, int $offset): array
    {
        [$where, $args] = $this->where($project, $filter, $today);
        return array_map($this->hydrate(...), $this->db->fetchAll('SELECT t.*, o.public_id, o.title, o.priority, o.status AS finding_status' . self::JOIN . $where . ' ORDER BY (t.status = \'done\'), (t.due IS NULL), t.due, o.priority DESC, t.id DESC LIMIT 25 OFFSET ' . max(0, $offset), $args));
    }
    public function count(Project $project, TaskFilter $filter, string $today): int
    {
        [$where, $args] = $this->where($project, $filter, $today);
        return (int) $this->db->fetchValue('SELECT COUNT(*)' . self::JOIN . $where, $args);
    }
    public function save(Project $project, string $opportunityId, TaskInput $input, int $revision, ?string $via = null): bool
    {
        $args = [$input->status, $input->assignee ?: null, $input->due ?: null, $input->notes, $input->url, $input->brief ?: null, $input->annotation ?: null, $via, $this->clock->now()->format('Y-m-d H:i:s')];
        if ($revision === 0) {
            try {
                return $this->db->execute('INSERT INTO opportunity_tasks (status, assignee, due, notes, url, brief, annotation, via, updated_at, workspace_id, project_id, opportunity_id) SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, p.workspace_id, p.id, o.id FROM opportunities o JOIN projects p ON p.id = o.project_id JOIN workspaces w ON w.id = p.workspace_id WHERE p.workspace_id = ? AND p.id = ? AND o.public_id = ? AND p.deleted_at IS NULL AND w.deleted_at IS NULL', [...$args, $project->workspaceId, $project->id, $opportunityId]) === 1;
            } catch (\SeoControlCenter\Core\Database\DatabaseException $e) {
                $previous = $e->getPrevious();
                if ($previous instanceof \PDOException && ($previous->errorInfo[1] ?? null) === 1062) {
                    return false;
                }
                throw $e;
            }
        }
        return $this->db->execute('UPDATE opportunity_tasks t JOIN opportunities o ON o.id = t.opportunity_id AND o.project_id = t.project_id JOIN projects p ON p.id = t.project_id AND p.workspace_id = t.workspace_id JOIN workspaces w ON w.id = t.workspace_id SET t.status = ?, t.assignee = ?, t.due = ?, t.notes = ?, t.url = ?, t.brief = ?, t.annotation = ?, t.via = ?, t.updated_at = ?, t.revision = t.revision + 1 WHERE t.workspace_id = ? AND t.project_id = ? AND o.public_id = ? AND t.revision = ? AND p.deleted_at IS NULL AND w.deleted_at IS NULL', [...$args, $project->workspaceId, $project->id, $opportunityId, $revision]) === 1;
    }
    /** @return array{string, list<int|string>} */
    private function where(Project $project, TaskFilter $filter, string $today): array
    {
        $where = ' WHERE t.workspace_id = ? AND t.project_id = ?';
        $args = [$project->workspaceId, $project->id];
        foreach (['status' => $filter->status, 'url' => $filter->url] as $column => $value) {
            if ($value !== '') {
                $where .= ' AND ' . ($column === 'url' ? 'BINARY ' : '') . 't.' . $column . ' = ?';
                $args[] = $value;
            }
        }
        if ($filter->assignee === 'unassigned') {
            $where .= ' AND t.assignee IS NULL';
        } elseif ($filter->assignee !== '') {
            $where .= ' AND t.assignee = ?';
            $args[] = $filter->assignee;
        }
        if ($filter->overdue) {
            $where .= " AND t.status <> 'done' AND t.due < ?";
            $args[] = $today;
        }
        if ($filter->priority > 0) {
            $where .= ' AND o.priority >= ?';
            $args[] = $filter->priority;
        }
        if ($filter->search !== '') {
            $where .= " AND (o.title LIKE ? ESCAPE '!' OR t.notes LIKE ? ESCAPE '!' OR t.url LIKE ? ESCAPE '!')";
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filter->search) . '%';
            array_push($args, $pattern, $pattern, $pattern);
        }
        return [$where, $args];
    }
    /** @param array<string, mixed> $row */
    private function hydrate(array $row): SeoTask
    {
        return new SeoTask((string) $row['public_id'], (string) $row['title'], (float) $row['priority'], (string) $row['finding_status'], new TaskInput((string) $row['status'], (string) ($row['assignee'] ?? ''), (string) ($row['due'] ?? ''), (string) $row['notes'], (string) $row['url'], (string) ($row['brief'] ?? ''), (string) ($row['annotation'] ?? '')), (int) $row['revision'], (string) $row['updated_at'], isset($row['via']) ? (string) $row['via'] : null);
    }
}
