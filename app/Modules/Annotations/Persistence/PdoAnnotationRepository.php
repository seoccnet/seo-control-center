<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Annotations\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Annotations\Annotation;
use SeoControlCenter\Modules\Annotations\AnnotationInput;
use SeoControlCenter\Modules\Annotations\AnnotationRepository;

final readonly class PdoAnnotationRepository implements AnnotationRepository
{
    public function __construct(private Connection $db, private Clock $clock) {}

    public function list(Project $project, ?DateRange $range, ?string $url, int $limit, int $offset): array
    {
        [$where, $args] = $this->where($project, $range, $url);
        return array_map($this->hydrate(...), $this->db->fetchAll('SELECT * FROM seo_annotations WHERE ' . $where . ' ORDER BY changed_on DESC, id DESC LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, min(1000000, $offset)), $args));
    }

    public function count(Project $project, ?DateRange $range, ?string $url): int
    {
        [$where, $args] = $this->where($project, $range, $url);
        return (int) ($this->db->fetchOne('SELECT COUNT(*) AS n FROM seo_annotations WHERE ' . $where, $args)['n'] ?? 0);
    }

    public function find(Project $project, string $publicId): ?Annotation
    {
        [$where, $args] = $this->where($project, null, null);
        $row = $this->db->fetchOne('SELECT * FROM seo_annotations WHERE ' . $where . ' AND public_id = ?', [...$args, $publicId]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function save(Project $project, AnnotationInput $input, int $userId, ?string $publicId, ?int $expectedRevision = null, ?string $via = null): Annotation
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $values = [$input->date, $input->kind, $input->title, $input->note, $input->url, $input->url === null ? null : hash('sha256', $input->url), $via];
        if ($publicId === null) {
            $publicId = Uuid::v7();
            $this->db->execute('INSERT INTO seo_annotations (changed_on, kind, title, note, url, url_hash, via, public_id, workspace_id, project_id, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$values, $publicId, $project->workspaceId, $project->id, $userId, $now, $now]);
        } else {
            $changed = $this->db->execute('UPDATE seo_annotations SET changed_on = ?, kind = ?, title = ?, note = ?, url = ?, url_hash = ?, via = ?, updated_at = ?, revision = revision + 1 WHERE workspace_id = ? AND project_id = ? AND public_id = ? AND deleted_at IS NULL' . ($expectedRevision !== null ? ' AND revision = ?' : ''), [...$values, $now, $project->workspaceId, $project->id, $publicId, ...($expectedRevision !== null ? [$expectedRevision] : [])]);
            if ($changed !== 1) {
                throw new \SeoControlCenter\Core\Http\Exception\HttpException(409, 'This change note was changed in the meantime. Read it again before saving.');
            }
        }
        return $this->find($project, $publicId) ?? throw new \RuntimeException('Annotation was not saved.');
    }

    public function remove(Project $project, string $publicId): void
    {
        $this->db->execute('UPDATE seo_annotations SET deleted_at = ?, updated_at = ? WHERE workspace_id = ? AND project_id = ? AND public_id = ? AND deleted_at IS NULL', [$this->clock->now()->format('Y-m-d H:i:s'), $this->clock->now()->format('Y-m-d H:i:s'), $project->workspaceId, $project->id, $publicId]);
    }

    /** @return array{string, list<int|string>} */
    private function where(Project $project, ?DateRange $range, ?string $url): array
    {
        $where = 'workspace_id = ? AND project_id = ? AND deleted_at IS NULL';
        $args = [$project->workspaceId, $project->id];
        if ($range !== null) {
            $where .= ' AND changed_on BETWEEN ? AND ?';
            $args[] = $range->fromString();
            $args[] = $range->toString();
        }
        if ($url !== null) {
            $where .= ' AND (url_hash IS NULL OR (url_hash = ? AND BINARY url = BINARY ?))';
            $args[] = hash('sha256', $url);
            $args[] = $url;
        }
        return [$where, $args];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Annotation
    {
        return new Annotation((string) $row['public_id'], (string) $row['changed_on'], (string) $row['kind'], (string) $row['title'], (string) $row['note'], $row['url'] === null ? null : (string) $row['url'], (string) $row['created_at'], (string) $row['updated_at'], (int) ($row['revision'] ?? 1), isset($row['via']) ? (string) $row['via'] : null);
    }
}
