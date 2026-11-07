<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Domain\Opportunities\ConfidenceObservation;
use SeoControlCenter\Domain\Opportunities\ConfidenceReader;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Shared\DateRange;

final readonly class PdoConfidenceReader implements ConfidenceReader
{
    public function __construct(private Connection $db) {}

    public function observations(int $projectId, SerpDevice $device, DateRange $range): array
    {
        $out = [];
        foreach ([['query', 'queries', 'query_id', 'search_queries', 'query'], ['page', 'pages', 'page_id', 'pages', 'url']] as [$kind, $table, $id, $dictionary, $key]) {
            $rows = $this->db->fetchAll(sprintf('SELECT d.%s AS subject, a.* FROM (
                SELECT %s AS subject_id, SUM(impressions) AS impressions, SUM(clicks) AS clicks,
                    SUM(position * impressions) / NULLIF(SUM(impressions), 0) AS position,
                    STDDEV_SAMP(CASE WHEN impressions > 0 AND position > 0 THEN position END) AS deviation,
                    COUNT(CASE WHEN impressions > 0 AND position > 0 THEN 1 END) AS days, MAX(date) AS date
                FROM search_console_%s WHERE project_id = ? AND date BETWEEN ? AND ?
                GROUP BY %s ORDER BY impressions DESC, subject_id LIMIT 500
            ) a JOIN %s d ON d.id = a.subject_id AND d.project_id = ? ORDER BY a.impressions DESC, a.subject_id', $key, $id, $table, $id, $dictionary), [$projectId, $range->fromString(), $range->toString(), $projectId]);
            foreach ($rows as $r) {
                $out[] = new ConfidenceObservation($kind, (string) $r['subject'], 'search_console', (int) $r['impressions'], (int) $r['clicks'], $r['position'] !== null ? (float) $r['position'] : null, $r['deviation'] !== null ? (float) $r['deviation'] : null, (int) $r['days'], (string) $r['date']);
            }
        }
        $rows = $this->db->fetchAll('SELECT pk.keyword, AVG(rs.position) AS position, STDDEV_SAMP(rs.position) AS deviation,
            COUNT(rs.position) AS days, MAX(rs.checked_on) AS date
            FROM ranking_snapshots rs JOIN project_keywords pk ON pk.id = rs.project_keyword_id AND pk.project_id = ?
            WHERE rs.project_id = ? AND rs.device = ? AND rs.checked_on BETWEEN ? AND ?
            GROUP BY pk.id, pk.keyword ORDER BY pk.id LIMIT 1000', [$projectId, $projectId, $device->value, $range->fromString(), $range->toString()]);
        foreach ($rows as $r) {
            $out[] = new ConfidenceObservation('query', (string) $r['keyword'], 'rankings', null, null, $r['position'] !== null ? (float) $r['position'] : null, $r['deviation'] !== null ? (float) $r['deviation'] : null, (int) $r['days'], (string) $r['date']);
        }
        return $out;
    }
}
