<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Opportunities\PanelSignalReader;
use SeoControlCenter\Domain\Shared\DateRange;

final class PanelSignalReaderTest extends FullStackTestCase
{
    public function testEmptySourcesStayUnknownAndWorkspaceScopeIsEnforced(): void
    {
        $owner = $this->createUser('panels@acme.test', 'Panels');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace']->id;
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'acme.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", ['0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fd1', $workspace]);
        $project = $this->db->lastInsertId();
        $reader = $this->app->make(PanelSignalReader::class);
        $range = DateRange::endingOn('2026-10-06', 28);
        $snapshot = $reader->snapshot($project, $workspace, $range, '2026-10-08');
        self::assertSame('missing', $snapshot->coverage['query_splits']['status']);
        self::assertSame('missing', $snapshot->coverage['indexing']['status']);
        self::assertSame('missing', $snapshot->coverage['internal_links']['status']);
        self::assertSame([], $snapshot->inspections);
        self::assertSame([], $reader->snapshot($project, $workspace + 1000, $range, '2026-10-08')->coverage);
        $this->db->execute('INSERT INTO seo_annotations (public_id, workspace_id, project_id, changed_on, title, note, kind, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', ['0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fd2', $workspace, $project, '2026-10-02', 'Changed title', '', 'content', '2026-10-02 00:00:00', '2026-10-02 00:00:00']);
        self::assertSame('Changed title', $reader->snapshot($project, $workspace, $range, '2026-10-08')->annotations[0]['title']);
        $audit = $this->app->make(\SeoControlCenter\Modules\SiteAudit\SiteAuditRepository::class)->create($project, $workspace, 'https://acme.test/a', 10, 'manual', null);
        $this->db->execute("UPDATE site_audits SET status = 'completed', finished_at = '2026-10-06 00:00:00' WHERE id = ?", [$audit->id]);
        $this->db->execute("UPDATE site_audit_pages SET state = 'done', word_count = 100 WHERE audit_id = ?", [$audit->id]);
        $auditPage = (int) $this->db->fetchValue('SELECT id FROM site_audit_pages WHERE audit_id = ?', [$audit->id]);
        $this->db->execute("INSERT INTO site_audit_issues (audit_id, page_id, type, severity) VALUES (?, ?, 'thin_content', 'warning'), (?, ?, 'thin_content', 'warning')", [$audit->id, $auditPage, $audit->id, $auditPage]);
        $this->app->make(\SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore::class)->replaceDay($project, '2026-10-06', ['query' => [], 'page' => [new \SeoControlCenter\Domain\SearchConsole\DimensionRow('https://acme.test/a', new \SeoControlCenter\Domain\SearchConsole\Metrics(30, 100, 8))], 'device' => [], 'country' => []]);
        $this->db->execute("INSERT INTO ga4_landing_pages (project_id, date, url_hash, url, sessions, conversions, revenue) VALUES (?, '2026-10-06', UNHEX(SHA2('/a', 256)), '/a', 30, 3, 45)", [$project]);
        $snapshot = $reader->snapshot($project, $workspace, $range, '2026-10-08');
        self::assertSame('available', $snapshot->coverage['internal_links']['status']);
        self::assertSame(1, $snapshot->issueTraffic['thin_content']['pages'], 'Duplicate issues on a page cannot duplicate exposure.');
        self::assertSame(30, $snapshot->issueTraffic['thin_content']['clicks']);
        self::assertArrayHasKey('conversions', $snapshot->issueTraffic['thin_content']);
        self::assertArrayHasKey('revenue', $snapshot->issueTraffic['thin_content']);
        self::assertSame(3.0, $snapshot->issueTraffic['thin_content']['conversions']);
        self::assertSame(45.0, $snapshot->issueTraffic['thin_content']['revenue']);

        // Links from the text are kept apart from menu and footer links (0.42.0).
        foreach (['https://acme.test/b', 'https://acme.test/c'] as $url) {
            $this->db->execute("INSERT INTO site_audit_pages (audit_id, url, url_hash, depth, state) VALUES (?, ?, ?, 1, 'done')", [$audit->id, $url, sha1($url)]);
        }
        $ids = array_column($this->db->fetchAll('SELECT id, url FROM site_audit_pages WHERE audit_id = ?', [$audit->id]), 'id', 'url');
        $this->db->execute("INSERT INTO site_audit_links (audit_id, from_page_id, to_page_id, link_class) VALUES (?, ?, ?, 'editorial'), (?, ?, ?, 'template')", [$audit->id, $ids['https://acme.test/a'], $ids['https://acme.test/b'], $audit->id, $ids['https://acme.test/a'], $ids['https://acme.test/c']]);
        $snapshot = $reader->snapshot($project, $workspace, $range, '2026-10-08');
        self::assertSame(['https://acme.test/b', 'https://acme.test/c'], $snapshot->outgoing['https://acme.test/a'] ?? null);
        self::assertSame(['https://acme.test/b'], $snapshot->bodyOutgoing['https://acme.test/a'] ?? null);

    }
}
