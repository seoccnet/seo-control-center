<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Annotations\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\Annotations\MeasurementPlanRepository;

final readonly class PdoMeasurementPlanRepository implements MeasurementPlanRepository
{
    public function __construct(private Connection $db) {}

    public function plannedChanges(Project $project, int $limit): array
    {
        return array_map(
            static fn(array $r): string => (string) $r['public_id'],
            $this->db->fetchAll(
                'SELECT a.public_id FROM measurement_plans m JOIN seo_annotations a ON a.id = m.annotation_id AND a.deleted_at IS NULL
                 WHERE m.workspace_id = ? AND m.project_id = ? ORDER BY a.changed_on DESC, a.id DESC LIMIT ' . max(1, min(500, $limit)),
                [$project->workspaceId, $project->id],
            ),
        );
    }

    public function find(Project $project, string $annotationId): ?array
    {
        $row = $this->db->fetchOne('SELECT m.*, a.public_id AS annotation_public_id FROM measurement_plans m JOIN seo_annotations a ON a.id = m.annotation_id AND a.deleted_at IS NULL WHERE m.workspace_id = ? AND m.project_id = ? AND a.public_id = ?', [$project->workspaceId, $project->id, $annotationId]);
        if ($row === null) {
            return null;
        }
        $list = static function (mixed $json): array {
            $data = json_decode((string) $json, true);

            return is_array($data) ? array_values(array_filter($data, is_string(...))) : [];
        };
        $baseline = $row['baseline'] !== null ? json_decode((string) $row['baseline'], true) : null;

        return [
            'public_id' => (string) $row['public_id'],
            'annotation_id' => (string) $row['annotation_public_id'],
            'objective' => (string) $row['objective'],
            'metric' => (string) $row['metric'],
            'target_pages' => $list($row['target_pages']),
            'target_queries' => $list($row['target_queries']),
            'control_pages' => $list($row['control_pages']),
            'days' => (int) $row['days'],
            'goal' => $row['goal'] !== null ? (float) $row['goal'] : null,
            'review_dates' => $list($row['review_dates']),
            'timezone' => (string) $row['timezone'],
            'baseline' => is_array($baseline) && is_array($baseline['targets'] ?? null) ? ['targets' => $baseline['targets'], 'controls' => is_array($baseline['controls'] ?? null) ? $baseline['controls'] : null] : null,
            'frozen_at' => $row['frozen_at'] !== null ? (string) $row['frozen_at'] : null,
            'version' => (int) $row['version'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    public function save(Project $project, string $annotationId, string $publicId, array $fields, int $expectedVersion, bool $clearBaseline, ?int $userId, string $now): bool
    {
        $annotation = $this->db->fetchValue('SELECT id FROM seo_annotations WHERE workspace_id = ? AND project_id = ? AND public_id = ? AND deleted_at IS NULL', [$project->workspaceId, $project->id, $annotationId]);
        if ($annotation === null) {
            return false;
        }
        $json = static fn(array $list): string => json_encode(array_values($list), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $values = [$fields['objective'], $fields['metric'], $json($fields['target_pages']), $json($fields['target_queries']), $json($fields['control_pages']), $fields['days'], $fields['goal'], $json($fields['review_dates']), $fields['timezone']];
        if ($expectedVersion === 0) {
            // A second first save loses to the unique key.
            return $this->db->execute('INSERT IGNORE INTO measurement_plans (public_id, workspace_id, project_id, annotation_id, objective, metric, target_pages, target_queries, control_pages, days, goal, review_dates, timezone, version, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)', [$publicId, $project->workspaceId, $project->id, (int) $annotation, ...$values, $userId, $now, $now]) === 1;
        }

        return $this->db->execute('UPDATE measurement_plans SET objective = ?, metric = ?, target_pages = ?, target_queries = ?, control_pages = ?, days = ?, goal = ?, review_dates = ?, timezone = ?' . ($clearBaseline ? ', baseline = NULL, frozen_at = NULL' : '') . ', version = version + 1, updated_by = ?, updated_at = ? WHERE annotation_id = ? AND project_id = ? AND version = ?', [...$values, $userId, $now, (int) $annotation, $project->id, $expectedVersion]) === 1;
    }

    public function freeze(Project $project, string $annotationId, array $baseline, int $expectedVersion, ?int $userId, string $now): bool
    {
        return $this->db->execute('UPDATE measurement_plans m JOIN seo_annotations a ON a.id = m.annotation_id SET m.baseline = ?, m.frozen_at = ?, m.version = m.version + 1, m.updated_by = ?, m.updated_at = ? WHERE m.workspace_id = ? AND m.project_id = ? AND a.public_id = ? AND a.deleted_at IS NULL AND m.version = ?', [json_encode($baseline, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), $now, $userId, $now, $project->workspaceId, $project->id, $annotationId, $expectedVersion]) === 1;
    }
}
