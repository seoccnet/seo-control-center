<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ContentInventory\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Modules\ContentInventory\InventorySources;

/**
 * Read-only joins over other modules' stored records, as the Pages list
 * already does (ADR-042). Each source is bounded; the coverage says when a
 * bound was reached, so a missing row is never read as "no page".
 */
final readonly class PdoInventorySources implements InventorySources
{
    public const int LIMIT = 5000;

    public function __construct(private Connection $db) {}

    public function records(int $projectId): array
    {
        $records = [];
        $coverage = [];

        $audit = $this->db->fetchOne("SELECT id, finished_at FROM site_audits WHERE project_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 1", [$projectId]);
        $rows = $audit === null ? [] : $this->db->fetchAll("SELECT url, status_code, content_type, redirect_to, canonical, noindex, title, error, crawled_at FROM site_audit_pages WHERE audit_id = ? AND state = 'done' ORDER BY id LIMIT " . (self::LIMIT + 1), [(int) $audit['id']]);
        foreach (array_slice($rows, 0, self::LIMIT) as $r) {
            $code = $r['status_code'] !== null ? (int) $r['status_code'] : null;
            $html = $r['content_type'] === null || str_starts_with(strtolower((string) $r['content_type']), 'text/html');
            if ($code !== null && $code < 300 && !$html) {
                continue;
            }
            [$status, $target, $detail] = match (true) {
                $code !== null && $code >= 300 && $code < 400 => ['redirect', $r['redirect_to'], 'HTTP ' . $code . ' to ' . (string) $r['redirect_to']],
                $code === 404 || $code === 410 => ['gone', null, 'HTTP ' . $code],
                $code !== null && $code < 300 => ['ok', $r['canonical'], 'HTTP ' . $code . ((bool) $r['noindex'] ? ', noindex' : '') . ($r['canonical'] !== null && $r['canonical'] !== $r['url'] ? ', canonical ' . (string) $r['canonical'] : '')],
                default => ['error', null, $code !== null ? 'HTTP ' . $code : (string) ($r['error'] ?? 'no response')],
            };
            $records[] = ['source' => 'crawl', 'url' => (string) $r['url'], 'ref' => (string) $r['url'], 'title' => $r['title'] !== null ? (string) $r['title'] : null, 'status' => $status, 'observed_at' => $r['crawled_at'] !== null ? (string) $r['crawled_at'] : null, 'detail' => $detail, 'target' => $target !== null ? (string) $target : null, 'noindex' => (bool) $r['noindex']];
        }
        $coverage['crawl'] = ['observed_at' => $audit !== null && $audit['finished_at'] !== null ? (string) $audit['finished_at'] : null, 'count' => min(count($rows), self::LIMIT), 'limited' => count($rows) > self::LIMIT, 'note' => $audit === null ? 'No completed site audit yet.' : 'Pages the latest completed crawl fetched; the crawl itself has page limits.'];

        $rows = $this->db->fetchAll('SELECT url, lastmod, first_seen_at FROM indexnow_urls WHERE project_id = ? ORDER BY first_seen_at DESC LIMIT ' . (self::LIMIT + 1), [$projectId]);
        $checked = $this->db->fetchValue('SELECT sitemap_checked_at FROM indexnow_settings WHERE project_id = ?', [$projectId]);
        foreach (array_slice($rows, 0, self::LIMIT) as $r) {
            $records[] = ['source' => 'sitemap', 'url' => (string) $r['url'], 'ref' => (string) $r['url'], 'title' => null, 'status' => 'listed', 'observed_at' => is_string($checked) ? $checked : (string) $r['first_seen_at'], 'detail' => $r['lastmod'] !== null ? 'lastmod ' . (string) $r['lastmod'] : 'no lastmod'];
        }
        $coverage['sitemap'] = ['observed_at' => is_string($checked) ? $checked : null, 'count' => min(count($rows), self::LIMIT), 'limited' => count($rows) > self::LIMIT, 'note' => $rows === [] ? 'No sitemap is read: set one under IndexNow to list sitemap URLs here.' : 'URLs in the sitemap watched by IndexNow.'];

        $last = $this->db->fetchValue('SELECT MAX(date) FROM search_console_pages WHERE project_id = ?', [$projectId]);
        $rows = !is_string($last) ? [] : $this->db->fetchAll('SELECT p.url, SUM(s.impressions) AS impressions, SUM(s.clicks) AS clicks, MAX(s.date) AS last_date FROM search_console_pages s JOIN pages p ON p.id = s.page_id AND p.project_id = s.project_id
            WHERE s.project_id = ? AND s.date BETWEEN DATE_SUB(?, INTERVAL 27 DAY) AND ? GROUP BY s.page_id, p.url ORDER BY impressions DESC LIMIT ' . (self::LIMIT + 1), [$projectId, $last, $last]);
        foreach (array_slice($rows, 0, self::LIMIT) as $r) {
            $records[] = ['source' => 'search_console', 'url' => (string) $r['url'], 'ref' => (string) $r['url'], 'title' => null, 'status' => 'impressions', 'observed_at' => (string) $r['last_date'], 'detail' => number_format((int) $r['impressions']) . ' impressions, ' . number_format((int) $r['clicks']) . ' clicks in 28 days', 'impressions' => (int) $r['impressions'], 'clicks' => (int) $r['clicks']];
        }
        $coverage['search_console'] = ['observed_at' => is_string($last) ? $last : null, 'count' => min(count($rows), self::LIMIT), 'limited' => count($rows) > self::LIMIT, 'note' => 'Pages with Google impressions in the last 28 imported days. A page without impressions can still exist.'];

        $rows = $this->db->fetchAll('SELECT public_id, title, keyword, url, status, updated_at FROM content_briefs WHERE project_id = ? AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 1000', [$projectId]);
        foreach ($rows as $r) {
            $records[] = ['source' => 'brief', 'url' => $r['url'] !== null && (string) $r['url'] !== '' ? (string) $r['url'] : null, 'ref' => (string) $r['public_id'], 'title' => (string) $r['title'], 'status' => (string) $r['status'], 'observed_at' => (string) $r['updated_at'], 'detail' => 'Brief for "' . (string) $r['keyword'] . '"', 'keyword' => (string) $r['keyword']];
        }
        $coverage['brief'] = ['observed_at' => null, 'count' => count($rows), 'limited' => count($rows) >= 1000, 'note' => 'Content briefs; a brief is a plan, not a page.'];

        $rows = $this->db->fetchAll('SELECT public_id, main_keyword, url, published_on, checked_at FROM article_idea_tracking WHERE project_id = ? ORDER BY published_on DESC LIMIT 1000', [$projectId]);
        foreach ($rows as $r) {
            $records[] = ['source' => 'published', 'url' => (string) $r['url'], 'ref' => (string) $r['public_id'], 'title' => null, 'status' => 'published', 'observed_at' => (string) $r['published_on'], 'detail' => 'Marked published for "' . (string) $r['main_keyword'] . '"', 'keyword' => (string) $r['main_keyword']];
        }
        $coverage['published'] = ['observed_at' => null, 'count' => count($rows), 'limited' => false, 'note' => 'Articles marked as published from article ideas.'];

        $count = 0;
        foreach ($this->db->fetchAll('SELECT public_id, pillar_keyword, members, updated_at FROM topic_plans WHERE project_id = ? ORDER BY id LIMIT 200', [$projectId]) as $r) {
            $members = json_decode((string) $r['members'], true);
            foreach (is_array($members) ? $members : [] as $m) {
                if (is_array($m) && ($m['type'] ?? null) === 'page' && ($m['kept'] ?? false) === true && is_string($m['url'] ?? null)) {
                    $records[] = ['source' => 'topic_plan', 'url' => $m['url'], 'ref' => (string) $r['public_id'] . ':' . $m['url'], 'title' => null, 'status' => 'member', 'observed_at' => (string) $r['updated_at'], 'detail' => 'Your page in the plan "' . (string) $r['pillar_keyword'] . '"', 'keyword' => (string) $r['pillar_keyword']];
                    $count++;
                }
            }
        }
        $coverage['topic_plan'] = ['observed_at' => null, 'count' => $count, 'limited' => false, 'note' => 'Your pages kept in topic plans.'];

        $rows = $this->db->fetchAll('SELECT public_id, label, COALESCE(manual_owner_url, owner_url) AS url, computed_at FROM keyword_clusters WHERE project_id = ? AND retired_at IS NULL AND COALESCE(manual_owner_url, owner_url) IS NOT NULL ORDER BY demand DESC LIMIT 2000', [$projectId]);
        foreach ($rows as $r) {
            $records[] = ['source' => 'topic_owner', 'url' => (string) $r['url'], 'ref' => (string) $r['public_id'], 'title' => null, 'status' => 'owner', 'observed_at' => (string) $r['computed_at'], 'detail' => 'Owns the topic "' . (string) $r['label'] . '"', 'keyword' => (string) $r['label']];
        }
        $coverage['topic_owner'] = ['observed_at' => null, 'count' => count($rows), 'limited' => count($rows) >= 2000, 'note' => 'Intended owner pages of topics (Topics & owners).'];

        return ['records' => $records, 'coverage' => $coverage];
    }
}
