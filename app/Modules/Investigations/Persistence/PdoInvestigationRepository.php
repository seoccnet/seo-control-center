<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Investigations\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Investigations\Investigation;
use SeoControlCenter\Domain\Investigations\InvestigationItem;
use SeoControlCenter\Domain\Investigations\InvestigationRepository;
use SeoControlCenter\Domain\Projects\Project;

final readonly class PdoInvestigationRepository implements InvestigationRepository
{
    private const string COLUMNS = 'i.id, i.public_id, i.workspace_id, i.project_id, i.title, i.question, i.status, i.conclusion, i.via, i.revision, i.created_at, i.updated_at';

    public function __construct(private Connection $db, private Clock $clock) {}

    public function list(Project $project, ?string $status, int $limit, int $offset): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM investigations i WHERE i.workspace_id = ? AND i.project_id = ?' . ($status !== null ? ' AND i.status = ?' : '') . sprintf(' ORDER BY i.updated_at DESC, i.id DESC LIMIT %d OFFSET %d', max(1, $limit), max(0, $offset)),
            [$project->workspaceId, $project->id, ...($status !== null ? [$status] : [])],
        );
        $counts = [];
        if ($rows !== []) {
            $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
            foreach ($this->db->fetchAll('SELECT investigation_id, kind, COUNT(*) AS n FROM investigation_items WHERE investigation_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY investigation_id, kind', $ids) as $c) {
                $counts[(int) $c['investigation_id']][(string) $c['kind']] = (int) $c['n'];
            }
        }

        return array_map(static fn(array $r): Investigation => self::hydrate($r, [], $counts[(int) $r['id']] ?? []), $rows);
    }

    public function count(Project $project, ?string $status): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM investigations WHERE workspace_id = ? AND project_id = ?' . ($status !== null ? ' AND status = ?' : ''), [$project->workspaceId, $project->id, ...($status !== null ? [$status] : [])]);
    }

    public function find(Project $project, string $publicId): ?Investigation
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM investigations i WHERE i.workspace_id = ? AND i.project_id = ? AND i.public_id = ?', [$project->workspaceId, $project->id, $publicId]);
        if ($row === null) {
            return null;
        }
        $items = array_map(static fn(array $r): InvestigationItem => new InvestigationItem(
            (string) $r['public_id'],
            (string) $r['kind'],
            $r['ref_type'] !== null ? (string) $r['ref_type'] : null,
            $r['ref_id'] !== null ? (string) $r['ref_id'] : null,
            (string) $r['label'],
            $r['detail'] !== null ? (string) $r['detail'] : null,
            $r['observed_on'] !== null ? (string) $r['observed_on'] : null,
            $r['via'] !== null ? (string) $r['via'] : null,
            (string) $r['created_at'],
        ), $this->db->fetchAll('SELECT public_id, kind, ref_type, ref_id, label, detail, observed_on, via, created_at FROM investigation_items WHERE investigation_id = ? ORDER BY id LIMIT 1000', [(int) $row['id']]));
        $counts = [];
        foreach ($items as $item) {
            $counts[$item->kind] = ($counts[$item->kind] ?? 0) + 1;
        }

        return self::hydrate($row, $items, $counts);
    }

    public function create(Project $project, array $fields, ?int $userId, ?string $via): string
    {
        $id = Uuid::v7($this->clock);
        $now = $this->now();
        $this->db->execute(
            'INSERT INTO investigations (public_id, workspace_id, project_id, title, question, status, conclusion, created_by, via, revision, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
            [$id, $project->workspaceId, $project->id, $fields['title'], $fields['question'], $fields['status'], $fields['conclusion'], $userId, $via, $now, $now],
        );

        return $id;
    }

    public function update(Project $project, string $publicId, array $fields, ?int $expectedRevision, ?string $via): void
    {
        $changed = $this->db->execute(
            'UPDATE investigations SET title = ?, question = ?, status = ?, conclusion = ?, via = ?, revision = revision + 1, updated_at = ? WHERE workspace_id = ? AND project_id = ? AND public_id = ?' . ($expectedRevision !== null ? ' AND revision = ?' : ''),
            [$fields['title'], $fields['question'], $fields['status'], $fields['conclusion'], $via, $this->now(), $project->workspaceId, $project->id, $publicId, ...($expectedRevision !== null ? [$expectedRevision] : [])],
        );
        if ($changed === 0) {
            if ($this->find($project, $publicId) === null) {
                throw new HttpException(404, 'Investigation not found.');
            }
            throw new HttpException(409, 'This investigation was changed in the meantime. Read it again before saving.');
        }
    }

    public function addItems(Project $project, string $publicId, array $items, ?int $userId, ?string $via): array
    {
        $id = $this->internalId($project, $publicId);
        $now = $this->now();
        $ids = [];
        $this->db->transaction(function () use ($id, $items, $userId, $via, $now, &$ids): void {
            foreach ($items as $item) {
                $itemId = Uuid::v7($this->clock);
                $this->db->execute(
                    'INSERT INTO investigation_items (public_id, investigation_id, kind, ref_type, ref_id, label, detail, observed_on, added_by, via, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$itemId, $id, $item['kind'], $item['ref_type'], $item['ref_id'], $item['label'], $item['detail'], $item['observed_on'], $userId, $via, $now],
                );
                $ids[] = $itemId;
            }
            $this->db->execute('UPDATE investigations SET revision = revision + 1, updated_at = ? WHERE id = ?', [$now, $id]);
        });

        return $ids;
    }

    public function removeItem(Project $project, string $publicId, string $itemId): bool
    {
        $id = $this->internalId($project, $publicId);
        $removed = $this->db->execute('DELETE FROM investigation_items WHERE investigation_id = ? AND public_id = ?', [$id, $itemId]) > 0;
        if ($removed) {
            $this->db->execute('UPDATE investigations SET revision = revision + 1, updated_at = ? WHERE id = ?', [$this->now(), $id]);
        }

        return $removed;
    }

    public function itemCount(Project $project, string $publicId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM investigation_items WHERE investigation_id = ?', [$this->internalId($project, $publicId)]);
    }

    private function internalId(Project $project, string $publicId): int
    {
        $id = $this->db->fetchValue('SELECT id FROM investigations WHERE workspace_id = ? AND project_id = ? AND public_id = ?', [$project->workspaceId, $project->id, $publicId]);
        if ($id === null || $id === false) {
            throw new HttpException(404, 'Investigation not found.');
        }

        return (int) $id;
    }

    /**
     * @param array<string, mixed> $r
     * @param list<InvestigationItem> $items
     * @param array<string, int> $counts
     */
    private static function hydrate(array $r, array $items, array $counts): Investigation
    {
        return new Investigation(
            (string) $r['public_id'],
            (int) $r['project_id'],
            (int) $r['workspace_id'],
            (string) $r['title'],
            $r['question'] !== null ? (string) $r['question'] : null,
            (string) $r['status'],
            $r['conclusion'] !== null ? (string) $r['conclusion'] : null,
            $r['via'] !== null ? (string) $r['via'] : null,
            (int) $r['revision'],
            (string) $r['created_at'],
            (string) $r['updated_at'],
            $items,
            $counts,
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
