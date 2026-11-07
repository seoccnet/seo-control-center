<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Modules\ContentInventory\OverlapReviewRepository;

/** ADR-042: overlap decisions and crawled page text on MySQL. */
final class OverlapReviewDbTest extends FullStackTestCase
{
    public function testDecisionsAreVersionedAndPageTextComesFromTheNewestAudit(): void
    {
        $owner = $this->createUser('overlap@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace']->id;
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES ('0192f0a0-0000-7000-8000-000000000b01', ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$workspace]);
        $project = $this->db->lastInsertId();
        $repo = $this->app->make(OverlapReviewRepository::class);
        $key = str_repeat('a', 64);
        $members = ['https://shop.test/blog/a', 'https://shop.test/blog/b'];

        self::assertTrue($repo->decide($workspace, $project, $key, 'near_duplicate', $members, 'merge_planned', $members[0], 'Redirect b', 0, $owner['user']->id));
        self::assertFalse($repo->decide($workspace, $project, $key, 'near_duplicate', $members, 'keep_separate', null, '', 0, null), 'A second first decision is refused.');
        self::assertTrue($repo->decide($workspace, $project, $key, 'near_duplicate', $members, 'keep_separate', null, 'Different intents', 1, $owner['user']->id));
        self::assertFalse($repo->decide($workspace, $project, $key, 'near_duplicate', $members, 'undecided', null, '', 1, null), 'An old version is refused.');
        $review = $repo->reviews($project)[$key];
        self::assertSame(['keep_separate', null, 'Different intents', 2, $members], [$review['decision'], $review['retained_url'], $review['note'], $review['version'], $review['members']]);

        self::assertCount(0, $repo->pageTexts($project, 50)['pages'], 'No completed audit yet.');
        $this->db->execute("INSERT INTO site_audits (public_id, project_id, workspace_id, status, trigger_type, start_url, max_pages, created_at, updated_at, finished_at) VALUES ('0192f0a0-0000-7000-8000-000000000b02', ?, ?, 'completed', 'manual', 'https://shop.test/', 100, '2026-10-20 08:00:00', '2026-10-20 10:00:00', '2026-10-20 10:00:00')", [$project, $workspace]);
        $audit = $this->db->lastInsertId();
        foreach ([['https://shop.test/blog/a', 200, 0], ['https://shop.test/blog/b', 200, 0], ['https://shop.test/private', 200, 1], ['https://shop.test/old', 301, 0]] as [$url, $code, $noindex]) {
            $this->db->execute("INSERT INTO site_audit_pages (audit_id, url, url_hash, depth, state, status_code, noindex, title) VALUES (?, ?, ?, 1, 'done', ?, ?, 'Title')", [$audit, $url, sha1($url), $code, $noindex]);
            $this->db->execute('INSERT INTO site_audit_paragraphs (audit_id, page_id, position, text) VALUES (?, ?, 0, ?), (?, ?, 1, ?)', [$audit, $this->db->lastInsertId(), 'First paragraph of ' . $url, $audit, $this->db->lastInsertId(), 'Second paragraph.']);
        }
        $texts = $repo->pageTexts($project, 100);
        self::assertSame('2026-10-20 10:00:00', $texts['audit_at']);
        self::assertSame(['https://shop.test/blog/a', 'https://shop.test/blog/b'], array_column($texts['pages'], 'url'), 'Only indexable 2xx pages.');
        self::assertStringContainsString('First paragraph of https://shop.test/blog/a', $texts['pages'][0]['text']);
        self::assertSame(1, count($repo->pageTexts($project, 1)['pages']));

        // Finding 39 (0.44.1): product pages by Open Graph type or Product markup; "ProductCategory"-like names and other pages are not products.
        self::assertSame([], $repo->productUrls($project));
        foreach ([['https://shop.test/seeds/a', 'product', 'Product,BreadcrumbList'], ['https://shop.test/seeds/b', 'product', 'BreadcrumbList'], ['https://shop.test/seeds/c', 'website', 'ProductGroup'], ['https://shop.test/seeds/list', 'website', 'CollectionPage,ProductCollectionPage'], ['https://shop.test/about', 'website', 'WebPage']] as [$url, $og, $types]) {
            $this->db->execute("INSERT INTO site_audit_pages (audit_id, url, url_hash, depth, state, status_code, noindex, title, og_type, schema_types) VALUES (?, ?, ?, 1, 'done', 200, 0, 'Title', ?, ?)", [$audit, $url, sha1($url), $og, $types]);
        }
        $products = $repo->productUrls($project);
        sort($products);
        self::assertSame(['https://shop.test/seeds/a', 'https://shop.test/seeds/b', 'https://shop.test/seeds/c'], $products);
        self::assertSame([], $repo->productUrls($project + 100));
    }
}
