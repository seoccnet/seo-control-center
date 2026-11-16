<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Modules\SiteAudit\Crawler\HtmlPage;
use SeoControlCenter\Modules\SiteAudit\PageResult;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;

/** Sitemap membership, image signals and image sizes in the site audit (1.5.0) on the real engine. */
final class AuditSitemapImagePersistenceTest extends FullStackTestCase
{
    public function testSitemapMembershipImagesAndResourceSizes(): void
    {
        $owner = $this->createUser('sitemap@audit.test', 'Sitemap');
        self::assertNotNull($owner['workspace']);
        $publicId = $this->createProject($this->browserFor('sitemap@audit.test'), 'Sitemap', 'sitemap.test');
        $projectId = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$publicId]);
        $audits = $this->app->make(SiteAuditRepository::class);
        $audit = $audits->create($projectId, $owner['workspace']->id, 'https://sitemap.test/', 3, 'manual', $owner['user']->id);
        $audits->markRunning($audit->id);
        $audit = $audits->byId($audit->id) ?? self::fail('Audit lost.');

        // The start page is listed too; the page limit leaves room for one more sitemap page only.
        self::assertSame(1, $audits->seed($audit, ['https://sitemap.test/', 'https://sitemap.test/a', 'https://sitemap.test/b'], 2));
        self::assertSame(2, (int) $this->db->fetchValue('SELECT sitemap_urls FROM site_audits WHERE id = ?', [$audit->id]));

        $start = $audits->findPage($audit->id, 'https://sitemap.test/') ?? self::fail('Start page lost.');
        self::assertTrue($start->inSitemap);
        $html = HtmlPage::parse('<main><img src="/hero.jpg" loading="lazy"><a href="/c">C</a></main>', 'https://sitemap.test/');
        $audits->savePage($audit, $start, new PageResult('done', 200, 'text/html', page: $html, discovered: ['https://sitemap.test/c']));

        $saved = $audits->findPage($audit->id, 'https://sitemap.test/') ?? self::fail('Start page lost.');
        self::assertSame(['images' => 1, 'missing_dimensions' => 1, 'lazy_first' => true], $saved->imageSignals);
        self::assertTrue($saved->inSitemap, 'Saving a page keeps its sitemap flag.');
        self::assertTrue($audits->findPage($audit->id, 'https://sitemap.test/a')?->inSitemap);
        self::assertFalse($audits->findPage($audit->id, 'https://sitemap.test/c')?->inSitemap, 'Pages found through links are known not to be listed.');

        $resource = $audits->pendingResources($audit->id)[0] ?? self::fail('Image not sampled.');
        $audits->saveResource($audit->id, $resource['id'], 'done', 206, 524288, 'image/jpeg');
        $issues = array_map(static fn(array $i): string => $i['type']->value, $audits->resourceIssues($audit->id));
        self::assertContains('large_image', $issues);
        self::assertContains('legacy_image_format', $issues);
        self::assertNull($this->db->fetchValue('SELECT in_sitemap FROM site_audit_pages WHERE id = 0'), 'Sanity.');
    }
}
