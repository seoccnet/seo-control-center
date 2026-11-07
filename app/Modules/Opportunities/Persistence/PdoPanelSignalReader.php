<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Domain\Integrations\ConnectionRepository;
use SeoControlCenter\Domain\Opportunities\PanelSignalReader;
use SeoControlCenter\Domain\Opportunities\PanelSnapshot;
use SeoControlCenter\Domain\Opportunities\SignalCoverage;
use SeoControlCenter\Domain\SearchConsole\ProjectPropertyRepository;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Alerts\AlertRepository;
use SeoControlCenter\Modules\Indexing\IndexingRepository;

final readonly class PdoPanelSignalReader implements PanelSignalReader
{
    public function __construct(private Connection $db, private ProjectPropertyRepository $properties, private ConnectionRepository $connections, private IndexingRepository $indexing, private AlertRepository $alerts) {}

    public function snapshot(int $projectId, int $workspaceId, DateRange $range, string $asOf): PanelSnapshot
    {
        $publicId = $this->db->fetchValue('SELECT p.public_id FROM projects p JOIN workspaces w ON w.id = p.workspace_id AND w.deleted_at IS NULL WHERE p.id = ? AND p.workspace_id = ? AND p.deleted_at IS NULL', [$projectId, $workspaceId]);
        if (!is_string($publicId)) {
            return new PanelSnapshot();
        }
        $now = new \DateTimeImmutable($asOf);
        $since = $now->modify('-28 days')->format('Y-m-d');
        $until = $asOf . ' 23:59:59';
        $mid = $range->from->modify('+14 days')->format('Y-m-d');
        $splits = [];
        $rows = $this->db->fetchAll('SELECT q.query, p.url, SUM(s.clicks) AS clicks, SUM(s.impressions) AS impressions,
            SUM(CASE WHEN s.date < ? THEN s.position * s.impressions ELSE 0 END) / NULLIF(SUM(CASE WHEN s.date < ? THEN s.impressions ELSE 0 END), 0) AS before_position,
            SUM(CASE WHEN s.date >= ? THEN s.position * s.impressions ELSE 0 END) / NULLIF(SUM(CASE WHEN s.date >= ? THEN s.impressions ELSE 0 END), 0) AS after_position
            FROM search_console_query_pages s JOIN search_queries q ON q.id = s.query_id AND q.project_id = s.project_id
            JOIN pages p ON p.id = s.page_id AND p.project_id = s.project_id
            WHERE s.project_id = ? AND s.date BETWEEN ? AND ? GROUP BY s.query_id, s.page_id, q.query, p.url ORDER BY impressions DESC, s.query_id, s.page_id LIMIT 5000', [$mid, $mid, $mid, $mid, $projectId, $range->fromString(), $range->toString()]);
        foreach ($rows as $r) {
            $splits[] = ['query' => (string) $r['query'], 'url' => (string) $r['url'], 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'before' => $r['before_position'] !== null ? (float) $r['before_position'] : null, 'after' => $r['after_position'] !== null ? (float) $r['after_position'] : null];
        }
        $splitDate = $this->db->fetchValue('SELECT MAX(date) FROM search_console_query_page_days WHERE project_id = ?', [$projectId]);
        $coverage = ['query_splits' => SignalCoverage::at(is_string($splitDate) ? $splitDate : null, $now, 7)];
        if (count($splits) === 5000) {
            $coverage['query_splits'] = ['status' => 'missing', 'date' => is_string($splitDate) ? $splitDate : null, 'notice' => 'Joint query/page sample reached 5,000 rows; cannibalization analysis withheld to avoid incomplete URL shares'];
        }

        $former = [];
        foreach ($this->db->fetchAll('SELECT p.url, SUM(s.clicks) AS clicks FROM search_console_pages s JOIN pages p ON p.id = s.page_id AND p.project_id = s.project_id WHERE s.project_id = ? AND s.date BETWEEN ? AND ? GROUP BY s.page_id, p.url ORDER BY clicks DESC, s.page_id LIMIT 2000', [$projectId, $range->previous()->fromString(), $range->previous()->toString()]) as $row) {
            $former[(string) $row['url']] = (int) $row['clicks'];
        }
        $googleDate = $this->db->fetchValue('SELECT MAX(date) FROM search_console_daily WHERE project_id = ?', [$projectId]);
        $coverage['indexing_traffic'] = [...SignalCoverage::at(is_string($googleDate) ? $googleDate : null, $now, 7), 'date' => $range->previous()->toString()];
        $inspections = [];
        $link = $this->properties->linkFor($projectId);
        $connection = $link !== null ? $this->connections->findById($link->connectionId) : null;
        $coverage['indexing'] = ['status' => 'missing', 'date' => null];
        if ($link !== null && $connection?->workspaceId === $workspaceId && $connection->isActive() && $connection->provider === 'google') {
            $binding = hash('sha256', $link->connectionId . '\n' . $link->siteUrl);
            $lost = [];
            foreach ($this->indexing->changes($projectId, $binding, $since . ' 00:00:00') as $change) {
                if ($change['before'] === 'PASS' && $change['after'] !== 'PASS') {
                    $lost[$change['url']] = true;
                }
            }
            $dates = [];
            foreach ($this->indexing->records($projectId, $binding) as $record) {
                $state = SignalCoverage::at($record->error === null && $record->result !== null ? $record->checkedAt : null, $now, 14);
                $coverage['inspection:' . hash('sha256', $record->url)] = $state;
                if ($record->result === null || $record->checkedAt === null) {
                    continue;
                }
                $dates[] = $record->checkedAt;
                $richErrors = [];
                foreach ($record->result->richItems as $item) {
                    foreach ($item['issues'] as $issue) {
                        if ($issue['severity'] === 'ERROR') {
                            $richErrors[] = $item['type'] . ': ' . $issue['message'];
                        }
                    }
                }
                $inspections[] = ['url' => $record->url, 'verdict' => $record->result->verdict, 'coverage' => $record->result->coverage, 'date' => $record->checkedAt, 'lost' => isset($lost[$record->url]), 'former_clicks' => $former[$record->url] ?? null,
                    'google_canonical' => $record->result->googleCanonical, 'user_canonical' => $record->result->userCanonical, 'canonical_mismatch' => $record->result->canonicalMismatch(), 'rich_errors' => $richErrors];
            }
            $coverage['indexing'] = SignalCoverage::at($dates !== [] ? max($dates) : null, $now, 14);
        }

        $auditPages = $outgoing = $bodyOutgoing = $issueTraffic = [];
        $audit = $this->db->fetchOne("SELECT id, finished_at FROM site_audits WHERE project_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 1", [$projectId]);
        $coverage['audit_traffic'] = SignalCoverage::at(is_string($googleDate) ? $googleDate : null, $now, 7);
        $coverage['internal_links'] = SignalCoverage::at(is_string($audit['finished_at'] ?? null) ? $audit['finished_at'] : null, $now, 30);
        if ($audit !== null) {
            $auditId = (int) $audit['id'];
            $rows = $this->db->fetchAll('SELECT p.url, p.word_count, p.noindex, p.canonical, COUNT(*) OVER () AS total, COALESCE(i.inbound, 0) AS inbound
                FROM site_audit_pages p LEFT JOIN (SELECT to_page_id, COUNT(*) AS inbound FROM site_audit_links WHERE audit_id = ? GROUP BY to_page_id) i ON i.to_page_id = p.id
                WHERE p.audit_id = ? AND p.state = ? ORDER BY p.id LIMIT 2000', [$auditId, $auditId, 'done']);
            foreach ($rows as $r) {
                $auditPages[] = ['url' => (string) $r['url'], 'inbound' => (int) $r['inbound'], 'words' => $r['word_count'] !== null ? (int) $r['word_count'] : null, 'noindex' => (bool) $r['noindex'], 'canonical' => is_string($r['canonical']) ? $r['canonical'] : null];
            }
            $links = $this->db->fetchAll('SELECT a.url AS source, b.url AS target, l.link_class FROM site_audit_links l JOIN site_audit_pages a ON a.id = l.from_page_id AND a.audit_id = l.audit_id JOIN site_audit_pages b ON b.id = l.to_page_id AND b.audit_id = l.audit_id WHERE l.audit_id = ? ORDER BY l.from_page_id, l.to_page_id LIMIT 5001', [$auditId]);
            foreach ($links as $r) {
                $outgoing[(string) $r['source']][] = (string) $r['target'];
                // Text and related-card links; audits crawled before 0.35.0 have no class and count as text.
                if (!in_array($r['link_class'], ['template', 'product', 'other'], true)) {
                    $bodyOutgoing[(string) $r['source']][] = (string) $r['target'];
                }
            }
            if ((int) ($rows[0]['total'] ?? 0) > 2000 || count($links) > 5000) {
                $coverage['internal_links']['status'] = 'missing';
                $coverage['internal_links']['notice'] = 'Internal-link graph exceeds the 2,000-page/5,000-link analysis bound; suggestions withheld';
            }
            $rows = $this->db->fetchAll('SELECT i.type, COUNT(*) AS affected, SUM(g.clicks) AS clicks FROM (SELECT DISTINCT type, page_id FROM site_audit_issues WHERE audit_id = ?) i
                JOIN site_audit_pages p ON p.id = i.page_id AND p.audit_id = ?
                LEFT JOIN (SELECT p.url, SUM(s.clicks) AS clicks FROM search_console_pages s JOIN pages p ON p.id = s.page_id AND p.project_id = s.project_id WHERE s.project_id = ? AND s.date BETWEEN ? AND ? GROUP BY s.page_id, p.url) g ON BINARY g.url = BINARY p.url
                GROUP BY i.type', [$auditId, $auditId, $projectId, $range->fromString(), $range->toString()]);
            foreach ($rows as $r) {
                $issueTraffic[(string) $r['type']] = ['pages' => (int) $r['affected'], 'clicks' => $r['clicks'] !== null ? (int) $r['clicks'] : null];
            }
        }
        if ($audit !== null) {
            $rows = $this->db->fetchAll("SELECT i.type, SUM(g.sessions) AS sessions, SUM(g.conversions) AS conversions, SUM(g.revenue) AS revenue
                FROM (SELECT DISTINCT type, page_id FROM site_audit_issues WHERE audit_id = ?) i JOIN site_audit_pages p ON p.id = i.page_id AND p.audit_id = ?
                LEFT JOIN (SELECT TRIM(TRAILING '/' FROM SUBSTRING_INDEX(CASE WHEN url LIKE 'http%://%' THEN SUBSTRING(url, LOCATE('/', url, LOCATE('//', url) + 2)) ELSE url END, '?', 1)) AS path, SUM(sessions) AS sessions, SUM(conversions) AS conversions, SUM(revenue) AS revenue
                    FROM ga4_landing_pages WHERE project_id = ? AND date BETWEEN ? AND ? GROUP BY path) g
                ON BINARY g.path = BINARY TRIM(TRAILING '/' FROM SUBSTRING_INDEX(SUBSTRING(p.url, LOCATE('/', p.url, LOCATE('//', p.url) + 2)), '?', 1)) GROUP BY i.type", [(int) $audit['id'], (int) $audit['id'], $projectId, $range->fromString(), $range->toString()]);
            foreach ($rows as $r) {
                $type = (string) $r['type'];
                if (isset($issueTraffic[$type])) {
                    $issueTraffic[$type]['sessions'] = $r['sessions'] !== null ? (int) $r['sessions'] : null;
                    $issueTraffic[$type]['conversions'] = $r['conversions'] !== null ? (float) $r['conversions'] : null;
                    $issueTraffic[$type]['revenue'] = $r['revenue'] !== null ? (float) $r['revenue'] : null;
                }
            }
        }
        $annotations = [];
        foreach ($this->db->fetchAll('SELECT public_id, changed_on, title, url FROM seo_annotations WHERE project_id = ? AND workspace_id = ? AND deleted_at IS NULL AND changed_on BETWEEN ? AND ? ORDER BY changed_on DESC, id DESC LIMIT 200', [$projectId, $workspaceId, $since, $asOf]) as $r) {
            $annotations[] = ['id' => (string) $r['public_id'], 'date' => (string) $r['changed_on'], 'title' => (string) $r['title'], 'url' => is_string($r['url']) ? $r['url'] : null];
        }
        $coverage['annotations'] = ['status' => 'available', 'date' => $asOf];
        $pageChanges = [];
        $hasWatches = (int) $this->db->fetchValue('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['page_watch_changes']) === 1;
        $coverage['page_monitoring'] = ['status' => $hasWatches ? 'available' : 'missing', 'date' => $hasWatches ? $asOf : null];
        if ($hasWatches) {
            foreach ($this->db->fetchAll('SELECT public_id, url, detected_at, before_snapshot, after_snapshot FROM page_watch_changes WHERE project_id = ? AND detected_at BETWEEN ? AND ? ORDER BY detected_at DESC, public_id DESC LIMIT 500', [$projectId, $since . ' 00:00:00', $until]) as $r) {
                $before = json_decode((string) $r['before_snapshot'], true);
                $after = json_decode((string) $r['after_snapshot'], true);
                if (!is_array($before) || !is_array($after)) {
                    continue;
                }
                $fields = [];
                foreach (['title', 'canonical', 'noindex'] as $field) {
                    if (array_key_exists($field, $before) && array_key_exists($field, $after) && $before[$field] !== $after[$field] && ($field === 'noindex' ? $before[$field] !== null && $after[$field] !== null : ($before['html'] ?? false) && ($after['html'] ?? false))) {
                        $fields[] = $field;
                    }
                }
                if ($fields !== []) {
                    $pageChanges[] = ['id' => (string) $r['public_id'], 'url' => (string) $r['url'], 'date' => substr((string) $r['detected_at'], 0, 10), 'fields' => $fields];
                }
            }
        }
        $alerts = [];
        foreach ($this->alerts->recent($workspaceId, [$projectId], $since . ' 00:00:00', 200) as $alert) {
            if ($alert->projectId === $projectId && $alert->createdAt <= $until) {
                $alerts[] = ['id' => $alert->publicId, 'date' => substr($alert->createdAt, 0, 10), 'title' => $alert->title, 'url' => $alert->url, 'lines' => $alert->lines];
            }
        }
        $coverage['alerts'] = ['status' => 'available', 'date' => $asOf];
        return new PanelSnapshot($splits, $inspections, $auditPages, $outgoing, $issueTraffic, $annotations, $pageChanges, $alerts, $coverage, $publicId, $bodyOutgoing);
    }
}
