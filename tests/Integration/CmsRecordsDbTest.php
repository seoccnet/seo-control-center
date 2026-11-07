<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\ContentInventory\Inventory;
use SeoControlCenter\Modules\ContentInventory\ContentItemRepository;
use SeoControlCenter\Modules\ContentInventory\InventorySources;

/** ADR-042: CMS records and the stored sources the content inventory joins, on MySQL. */
final class CmsRecordsDbTest extends FullStackTestCase
{
    public function testRecordsAreVersionedAndSourcesKeepTheirOwnStatus(): void
    {
        $owner = $this->createUser('records@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace']->id;
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES ('0192f0a0-0000-7000-8000-000000000801', ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$workspace]);
        $project = $this->db->lastInsertId();
        $items = $this->app->make(ContentItemRepository::class);

        $draft = $items->add($workspace, $project, ['title' => 'How to dry herbs', 'url' => null, 'status' => 'draft', 'publish_on' => '2026-11-01', 'cms_id' => '1042', 'note' => ''], 'csv', $owner['user']->id);
        self::assertSame($draft->publicId, $items->findByCmsId($project, '1042')?->publicId);
        self::assertSame(1, $items->count($project));
        $data = ['title' => 'How to dry herbs', 'url' => null, 'status' => 'scheduled', 'publish_on' => '2026-11-01', 'cms_id' => '1042', 'note' => '', 'linked_url' => 'https://shop.test/blog/dry'];
        self::assertTrue($items->update($project, $draft->publicId, 1, $data));
        self::assertFalse($items->update($project, $draft->publicId, 1, $data), 'An old version is refused.');
        $saved = $items->find($project, $draft->publicId);
        self::assertSame(['scheduled', 2, 'https://shop.test/blog/dry'], [$saved?->status, $saved?->version, $saved?->matchUrl()]);

        $this->db->execute("INSERT INTO site_audits (public_id, project_id, workspace_id, status, trigger_type, start_url, max_pages, created_at, updated_at, finished_at) VALUES ('0192f0a0-0000-7000-8000-000000000802', ?, ?, 'completed', 'manual', 'https://shop.test/', 100, '2026-10-20 08:00:00', '2026-10-20 10:00:00', '2026-10-20 10:00:00')", [$project, $workspace]);
        $audit = $this->db->lastInsertId();
        foreach ([['https://shop.test/blog/dry', 200, 'text/html', null, 'Dry herbs'], ['https://shop.test/old', 301, null, 'https://shop.test/blog/dry', null]] as [$url, $code, $type, $to, $title]) {
            $this->db->execute("INSERT INTO site_audit_pages (audit_id, url, url_hash, depth, state, status_code, content_type, redirect_to, title, crawled_at) VALUES (?, ?, ?, 1, 'done', ?, ?, ?, ?, '2026-10-20 09:00:00')", [$audit, $url, sha1($url), $code, $type, $to, $title]);
        }
        $this->db->execute("INSERT INTO indexnow_urls (project_id, url_hash, url, lastmod, first_seen_at) VALUES (?, ?, 'https://shop.test/blog/listed', '2026-10-01', '2026-10-02 00:00:00')", [$project, hash('sha256', 'listed', true)]);
        $this->db->execute("INSERT INTO pages (project_id, url_hash, url, first_seen_at) VALUES (?, ?, 'https://shop.test/blog/dry/', '2026-10-01 00:00:00')", [$project, hash('sha256', 'dry', true)]);
        $pageId = $this->db->lastInsertId();
        $this->db->execute("INSERT INTO search_console_pages (project_id, date, page_id, clicks, impressions, position) VALUES (?, '2026-10-18', ?, 3, 120, 9.5), (?, '2026-10-19', ?, 1, 80, 9.1)", [$project, $pageId, $project, $pageId]);
        $this->db->execute("INSERT INTO content_briefs (public_id, workspace_id, project_id, title, keyword, url, status, sections, evidence, created_at, updated_at) VALUES ('0192f0a0-0000-7000-8000-000000000803', ?, ?, 'Brief: cure', 'cure herbs', NULL, 'draft', '{}', '[]', '2026-10-21 00:00:00', '2026-10-21 00:00:00')", [$workspace, $project]);

        $sources = $this->app->make(InventorySources::class)->records($project);
        self::assertSame(['crawl' => 2, 'sitemap' => 1, 'search_console' => 1, 'brief' => 1], array_count_values(array_column($sources['records'], 'source')));
        self::assertSame('2026-10-20 10:00:00', $sources['coverage']['crawl']['observed_at']);
        $search = array_values(array_filter($sources['records'], static fn(array $r): bool => $r['source'] === 'search_console'))[0];
        self::assertSame('200 impressions, 4 clicks in 28 days', $search['detail']);

        $rows = Inventory::build([...$sources['records'], ['source' => 'cms', 'url' => $saved?->matchUrl(), 'ref' => $draft->publicId, 'title' => $draft->title, 'status' => 'scheduled', 'observed_at' => null, 'detail' => 'Scheduled']]);
        $byKey = array_column($rows, null, 'key');
        self::assertSame('live', $byKey['shop.test/blog/dry']['state']);
        self::assertSame(['crawl', 'search_console', 'cms'], array_column($byKey['shop.test/blog/dry']['sources'], 'source'), 'Crawl, Search Console (trailing slash) and the linked CMS record meet on one row.');
        self::assertSame('redirect', $byKey['shop.test/old']['state']);
        self::assertSame('sitemap', $byKey['shop.test/blog/listed']['state']);

        self::assertTrue($items->remove($project, $draft->publicId));
        self::assertNull($items->find($project, $draft->publicId));
    }
}
