<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Opportunities\OpportunityConsolidator;
use SeoControlCenter\Domain\Opportunities\OpportunityOutcome;
use SeoControlCenter\Domain\Opportunities\OutcomeCalibration;
use SeoControlCenter\Domain\Opportunities\OutcomeMetrics;
use SeoControlCenter\Domain\Opportunities\OutcomeRepository;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;

final readonly class PdoOutcomeRepository implements OutcomeRepository
{
    public function __construct(private Connection $db, private Clock $clock) {}
    public function record(int $projectId, int $workspaceId, StoredOpportunity $opportunity, string $completedOn, ?OutcomeMetrics $baseline, ?string $annotationId, string $source, ?string $pageUrl = null): void
    {
        $row = $this->db->fetchOne('SELECT o.id, (SELECT MAX(h.id) FROM opportunity_status_history h WHERE h.opportunity_id = o.id) AS history_id FROM opportunities o JOIN projects p ON p.id = o.project_id AND p.workspace_id = ? AND p.deleted_at IS NULL JOIN workspaces w ON w.id = p.workspace_id AND w.deleted_at IS NULL WHERE o.project_id = ? AND o.public_id = ?', [$workspaceId, $projectId, $opportunity->publicId]);
        if ($row === null) {
            return;
        }
        if ($annotationId !== null && $this->db->fetchValue('SELECT public_id FROM seo_annotations WHERE project_id = ? AND workspace_id = ? AND public_id = ? AND deleted_at IS NULL', [$projectId, $workspaceId, $annotationId]) === null) {
            $annotationId = null;
        }
        $this->db->transaction(function (Connection $db) use ($projectId, $workspaceId, $opportunity, $completedOn, $baseline, $annotationId, $source, $row, $pageUrl): void {
            foreach ([28, 56] as $checkpoint) {
                $db->execute('INSERT INTO opportunity_outcomes (public_id, project_id, workspace_id, opportunity_id, type, subject, completed_on, checkpoint, predicted_clicks, raw_predicted_clicks, baseline_clicks, baseline_impressions, baseline_position, annotation_id, status_history_id, completion_source, created_at, page_url)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE annotation_id = COALESCE(annotation_id, VALUES(annotation_id)), page_url = COALESCE(page_url, VALUES(page_url))', [Uuid::v7($this->clock), $projectId, $workspaceId, (int) $row['id'], $opportunity->type->value, OpportunityConsolidator::key($opportunity), $completedOn, $checkpoint, $opportunity->estimatedClicks, $opportunity->rawEstimatedClicks ?? $opportunity->estimatedClicks, $baseline?->clicks, $baseline?->impressions, $baseline?->position, $annotationId, $row['history_id'], $source, $this->clock->now()->format('Y-m-d H:i:s'), $pageUrl !== null ? mb_substr($pageUrl, 0, 2048) : null]);
            }
        });
    }

    public function due(string $today, string $retryDate): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAll('SELECT r.*, o.public_id AS opportunity_public_id FROM opportunity_outcomes r JOIN opportunities o ON o.id = r.opportunity_id AND o.project_id = r.project_id JOIN projects p ON p.id = r.project_id AND p.workspace_id = r.workspace_id AND p.deleted_at IS NULL JOIN workspaces w ON w.id = p.workspace_id AND w.deleted_at IS NULL
            WHERE (r.subject LIKE \'query:%\' OR r.subject LIKE \'page:%\') AND r.measured_at IS NULL AND DATE_ADD(r.completed_on, INTERVAL (r.checkpoint + 2) DAY) <= ? AND (r.last_checked_at IS NULL OR r.last_checked_at < ?) ORDER BY r.last_checked_at, r.completed_on, r.id LIMIT 20', [$today, $retryDate . ' 00:00:00']));
    }

    public function measured(OpportunityOutcome $row, ?OutcomeMetrics $baseline, ?OutcomeMetrics $actual, string $checkedAt): void
    {
        $this->db->execute('UPDATE opportunity_outcomes r JOIN projects p ON p.id = r.project_id AND p.workspace_id = r.workspace_id AND p.deleted_at IS NULL JOIN workspaces w ON w.id = p.workspace_id AND w.deleted_at IS NULL SET r.baseline_clicks = COALESCE(r.baseline_clicks, ?), r.baseline_impressions = COALESCE(r.baseline_impressions, ?), r.baseline_position = COALESCE(r.baseline_position, ?), r.actual_clicks = ?, r.actual_impressions = ?, r.actual_position = ?, r.measured_at = ?, r.last_checked_at = ? WHERE r.project_id = ? AND r.workspace_id = ? AND r.public_id = ? AND r.measured_at IS NULL', [$baseline?->clicks, $baseline?->impressions, $baseline?->position, $actual?->clicks, $actual?->impressions, $actual?->position, $actual !== null && $baseline !== null ? $checkedAt : null, $checkedAt, $row->projectId, $row->workspaceId, $row->publicId]);
    }

    public function factors(int $projectId, string $since): array
    {
        $rows = $this->db->fetchAll("SELECT type, LEAST(2.0, GREATEST(0.0, (actual_clicks - baseline_clicks) * 30.0 / 28.0 / raw_predicted_clicks)) AS ratio, (implementation IN ('verified', 'confirmed') AND (overlaps IS NULL OR overlaps = '[]')) AS clean FROM opportunity_outcomes WHERE project_id = ? AND checkpoint = 56 AND completed_on >= ? AND measured_at IS NOT NULL AND raw_predicted_clicks > 0 AND baseline_impressions >= 200 AND actual_impressions >= 200 AND implementation <> 'not_implemented' LIMIT 5000", [$projectId, $since]);
        $ratios = [];
        foreach ($rows as $row) {
            $ratios[(string) $row['type']][(int) $row['clean'] === 1 ? 'clean' : 'other'][] = (float) $row['ratio'];
        }

        return OutcomeCalibration::preferClean($ratios);
    }

    public function find(int $projectId, int $workspaceId, string $outcomeId): ?OpportunityOutcome
    {
        $row = $this->db->fetchOne('SELECT r.*, o.public_id AS opportunity_public_id FROM opportunity_outcomes r JOIN opportunities o ON o.id = r.opportunity_id AND o.project_id = r.project_id WHERE r.project_id = ? AND r.workspace_id = ? AND r.public_id = ?', [$projectId, $workspaceId, $outcomeId]);

        return $row === null ? null : self::hydrate($row);
    }

    public function unverified(string $since, int $limit): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAll(sprintf("SELECT r.*, o.public_id AS opportunity_public_id FROM opportunity_outcomes r JOIN opportunities o ON o.id = r.opportunity_id AND o.project_id = r.project_id JOIN projects p ON p.id = r.project_id AND p.workspace_id = r.workspace_id AND p.deleted_at IS NULL JOIN workspaces w ON w.id = p.workspace_id AND w.deleted_at IS NULL WHERE r.checkpoint = 28 AND r.implementation = 'unverified' AND r.page_url IS NOT NULL AND r.completed_on >= ? ORDER BY r.completed_on, r.id LIMIT %d", max(1, $limit)), [$since]));
    }

    public function completions(int $projectId, string $from, string $to): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAll('SELECT r.*, o.public_id AS opportunity_public_id FROM opportunity_outcomes r JOIN opportunities o ON o.id = r.opportunity_id AND o.project_id = r.project_id WHERE r.project_id = ? AND r.checkpoint = 28 AND r.completed_on BETWEEN ? AND ? ORDER BY r.completed_on, r.id LIMIT 500', [$projectId, $from, $to]));
    }

    public function setImplementation(OpportunityOutcome $row, string $state, ?string $source, string $at): void
    {
        $this->db->execute('UPDATE opportunity_outcomes r JOIN opportunity_outcomes me ON me.opportunity_id = r.opportunity_id AND me.completed_on = r.completed_on AND me.project_id = r.project_id SET r.implementation = ?, r.implementation_source = ?, r.implementation_at = ? WHERE me.project_id = ? AND me.workspace_id = ? AND me.public_id = ?', [$state, $source !== null ? mb_substr($source, 0, 255) : null, $at, $row->projectId, $row->workspaceId, $row->publicId]);
    }

    public function setOverlaps(OpportunityOutcome $row, array $overlaps): void
    {
        $this->db->execute('UPDATE opportunity_outcomes SET overlaps = ? WHERE project_id = ? AND workspace_id = ? AND public_id = ?', [json_encode($overlaps, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $row->projectId, $row->workspaceId, $row->publicId]);
    }

    public function recent(int $projectId): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAll('SELECT r.*, o.public_id AS opportunity_public_id FROM opportunity_outcomes r JOIN opportunities o ON o.id = r.opportunity_id AND o.project_id = r.project_id WHERE r.project_id = ? ORDER BY r.completed_on DESC, r.id DESC LIMIT 50', [$projectId]));
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): OpportunityOutcome
    {
        $baseline = $row['baseline_clicks'] !== null && $row['baseline_impressions'] !== null ? new OutcomeMetrics((int) $row['baseline_clicks'], (int) $row['baseline_impressions'], $row['baseline_position'] !== null ? (float) $row['baseline_position'] : null) : null;
        $actual = $row['actual_clicks'] !== null && $row['actual_impressions'] !== null ? new OutcomeMetrics((int) $row['actual_clicks'], (int) $row['actual_impressions'], $row['actual_position'] !== null ? (float) $row['actual_position'] : null) : null;
        return new OpportunityOutcome((string) $row['public_id'], (int) $row['project_id'], (int) $row['workspace_id'], (string) $row['opportunity_public_id'], (string) $row['type'], (string) $row['subject'], (string) $row['completed_on'], (int) $row['checkpoint'], $row['predicted_clicks'] !== null ? (float) $row['predicted_clicks'] : null, $baseline, $actual, is_string($row['annotation_id']) ? $row['annotation_id'] : null, is_string($row['measured_at']) ? $row['measured_at'] : null, $row['raw_predicted_clicks'] !== null ? (float) $row['raw_predicted_clicks'] : null, is_string($row['page_url'] ?? null) ? $row['page_url'] : null, is_string($row['implementation'] ?? null) ? $row['implementation'] : OpportunityOutcome::UNVERIFIED, is_string($row['implementation_source'] ?? null) ? $row['implementation_source'] : null, is_string($row['implementation_at'] ?? null) ? $row['implementation_at'] : null, self::overlaps($row['overlaps'] ?? null));
    }

    /** @return list<array{kind: string, label: string, date: string, ref: ?string}> */
    private static function overlaps(mixed $json): array
    {
        $data = is_string($json) ? json_decode($json, true) : null;
        $out = [];
        foreach (is_array($data) ? $data : [] as $o) {
            if (is_array($o) && is_string($o['kind'] ?? null) && is_string($o['label'] ?? null) && is_string($o['date'] ?? null)) {
                $out[] = ['kind' => $o['kind'], 'label' => $o['label'], 'date' => $o['date'], 'ref' => is_string($o['ref'] ?? null) ? $o['ref'] : null];
            }
        }

        return $out;
    }
}
