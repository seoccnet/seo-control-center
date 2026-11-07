<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ContentBriefs\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\ContentBriefs\BriefInput;
use SeoControlCenter\Modules\ContentBriefs\BriefRepository;
use SeoControlCenter\Modules\ContentBriefs\ContentBrief;

final readonly class PdoBriefRepository implements BriefRepository
{
    public function __construct(private Connection $db, private Clock $clock) {}
    public function list(Project $project, string $status, string $search, int $limit, int $offset): array
    {
        [$where, $args] = $this->where($project, $status, $search);
        return array_map($this->hydrate(...), $this->db->fetchAll('SELECT * FROM content_briefs WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . max(1, min(25, $limit)) . ' OFFSET ' . max(0, $offset), $args));
    }
    public function count(Project $project, string $status, string $search): int
    {
        [$where, $args] = $this->where($project, $status, $search);
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM content_briefs WHERE ' . $where, $args);
    }
    public function find(Project $project, string $publicId): ?ContentBrief
    {
        [$where, $args] = $this->where($project, '', '');
        $row = $this->db->fetchOne('SELECT * FROM content_briefs WHERE ' . $where . ' AND public_id = ?', [...$args, $publicId]);
        return $row === null ? null : $this->hydrate($row);
    }
    public function create(Project $project, BriefInput $input, array $evidence, int $userId, ?string $via = null): ContentBrief
    {
        $id = Uuid::v7();
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->db->execute('INSERT INTO content_briefs (public_id, workspace_id, project_id, title, keyword, url, status, published_at, sections, evidence, created_by, via, created_at, updated_at) SELECT ?, workspace_id, id, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ? FROM projects WHERE id = ? AND workspace_id = ? AND deleted_at IS NULL', [$id, $input->title, $input->keyword, $input->url, $input->status, $input->status === 'published' ? $now : null, json_encode($input->sections, JSON_THROW_ON_ERROR), json_encode($evidence, JSON_THROW_ON_ERROR), $userId, $via, $now, $now, $project->id, $project->workspaceId]);
        return $this->find($project, $id) ?? throw new \RuntimeException('Brief was not saved.');
    }
    public function update(Project $project, string $publicId, BriefInput $input, int $revision, ?string $via = null): bool
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        // The first publication date stays while the brief remains published; leaving Published clears it.
        return $this->db->execute("UPDATE content_briefs SET title = ?, keyword = ?, url = ?, status = ?, published_at = CASE WHEN ? = 'published' THEN COALESCE(published_at, ?) ELSE NULL END, sections = ?, via = ?, updated_at = ?, revision = revision + 1 WHERE workspace_id = ? AND project_id = ? AND public_id = ? AND revision = ? AND deleted_at IS NULL", [$input->title, $input->keyword, $input->url, $input->status, $input->status, $now, json_encode($input->sections, JSON_THROW_ON_ERROR), $via, $now, $project->workspaceId, $project->id, $publicId, $revision]) === 1;
    }
    public function remove(Project $project, string $publicId, int $revision): bool
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        return $this->db->execute('UPDATE content_briefs SET deleted_at = ?, updated_at = ?, revision = revision + 1 WHERE workspace_id = ? AND project_id = ? AND public_id = ? AND revision = ? AND deleted_at IS NULL', [$now, $now, $project->workspaceId, $project->id, $publicId, $revision]) === 1;
    }
    /** @return array{string, list<int|string>} */
    private function where(Project $project, string $status, string $search): array
    {
        $where = 'workspace_id = ? AND project_id = ? AND deleted_at IS NULL';
        $args = [$project->workspaceId, $project->id];
        if ($status !== '') {
            $where .= ' AND status = ?';
            $args[] = $status;
        }
        if ($search !== '') {
            $where .= " AND (title LIKE ? ESCAPE '!' OR keyword LIKE ? ESCAPE '!' OR url LIKE ? ESCAPE '!')";
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            array_push($args, $pattern, $pattern, $pattern);
        }
        return [$where, $args];
    }
    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ContentBrief
    {
        $raw = json_decode((string) $row['sections'], true, flags: JSON_THROW_ON_ERROR);
        $sections = [];
        foreach (BriefInput::FIELDS as $key => $label) {
            $sections[$key] = is_array($raw) && is_string($raw[$key] ?? null) ? $raw[$key] : '';
        }
        $rawEvidence = json_decode((string) $row['evidence'], true, flags: JSON_THROW_ON_ERROR);
        $evidence = is_array($rawEvidence) ? array_values(array_filter($rawEvidence, is_string(...))) : [];
        return new ContentBrief((string) $row['public_id'], new BriefInput((string) $row['title'], (string) $row['keyword'], $row['url'] === null ? null : (string) $row['url'], (string) $row['status'], $sections), $evidence, (int) $row['revision'], (string) $row['created_at'], (string) $row['updated_at'], isset($row['published_at']) ? (string) $row['published_at'] : null, isset($row['via']) ? (string) $row['via'] : null);
    }
}
