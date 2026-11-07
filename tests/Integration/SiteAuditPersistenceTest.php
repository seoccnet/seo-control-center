<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Http\Client\HostResolver;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Modules\SiteAudit\AuditPage;
use SeoControlCenter\Modules\SiteAudit\IssueType;
use SeoControlCenter\Modules\SiteAudit\PageResult;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;
use SeoControlCenter\Modules\SiteAudit\SiteAuditService;
use SeoControlCenter\Tests\Support\Fakes\FakeHostResolver;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;

/**
 * Site audits, the crawl frontier, links, issues and pruning against MySQL/MariaDB.
 */
final class SiteAuditPersistenceTest extends FullStackTestCase
{
    private int $workspaceId;

    private int $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        $acme = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($acme['workspace']);
        $this->workspaceId = $acme['workspace']->id;
        $this->db->execute(
            "INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at)
             VALUES ('0192f0a0-0000-7000-8000-000000000021', ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$this->workspaceId],
        );
        $this->projectId = $this->db->lastInsertId();
    }

    public function testFrontierLinksIssuesAndPruning(): void
    {
        $repo = $this->app->make(SiteAuditRepository::class);
        self::assertNull($repo->settings($this->projectId));
        $repo->saveSettings($this->projectId, $this->workspaceId, 3, true, 1);
        self::assertSame(3, $repo->settings($this->projectId)?->maxPages);
        self::assertSame([['projectId' => $this->projectId, 'workspaceId' => $this->workspaceId, 'projectPublicId' => '0192f0a0-0000-7000-8000-000000000021']], $repo->weeklyProjects());

        $audit = $repo->create($this->projectId, $this->workspaceId, 'https://shop.test/', 3, 'manual', null);
        self::assertSame('queued', $audit->status);
        $repo->saveCrawlState($audit->id, ['sitemaps' => ['https://shop.test/child.xml'], 'seen' => ['https://shop.test/sitemap.xml']]);
        self::assertEquals(['sitemaps' => ['https://shop.test/child.xml'], 'seen' => ['https://shop.test/sitemap.xml']], $repo->crawlState($audit->id));
        self::assertSame($audit->id, $repo->find($this->projectId, $audit->publicId)?->id);
        self::assertNull($repo->find($this->projectId + 1, $audit->publicId));
        $repo->markRunning($audit->id);
        self::assertSame('running', $repo->byId($audit->id)?->status);

        // Sitemap URLs fill the audit up to the given share; the start page is already there.
        self::assertSame(1, $repo->seed($audit, ['https://shop.test/', 'https://shop.test/b', 'https://shop.test/x'], 2));
        self::assertSame(2, $repo->byId($audit->id)?->pagesFound);
        $seeded = $repo->pendingPages($audit->id, 10);
        self::assertSame(AuditPage::UNLINKED, $seeded[1]->depth);

        [$home] = $repo->pendingPages($audit->id, 10);
        self::assertSame('https://shop.test/', $home->url);
        // Four new links, but the audit has room for two more pages only.
        $repo->savePage($audit, $home, new PageResult('done', 200, 'text/html', durationMs: 90, bytes: 1000, internalLinks: 5, discovered: [
            'https://shop.test/', 'https://shop.test/a', 'https://shop.test/b', 'https://shop.test/c', 'https://shop.test/d',
        ], resources: [['url' => 'https://shop.test/missing.png', 'kind' => 'image_alt'], ['url' => 'https://outside.test/missing', 'kind' => 'external']]));
        $resources = $repo->pendingResources($audit->id);
        self::assertCount(2, $resources);
        self::assertSame([], $repo->pendingResources($audit->id + 100));
        foreach ($resources as $resource) {
            $repo->saveResource($audit->id, $resource['id'], 'done', 404);
        }
        self::assertSame([], $repo->pendingResources($audit->id));
        self::assertCount(3, $repo->resourceIssues($audit->id));
        self::assertSame([$home->id], array_values(array_unique(array_column($repo->resourceIssues($audit->id), 'pageId'))));
        self::assertSame([], $repo->resourceIssues($audit->id + 100));
        // The seeded /b is now linked from the home page, so it has depth 1 and comes first.
        [$b, $a] = $repo->pendingPages($audit->id, 10);
        self::assertSame(['https://shop.test/b', 1], [$b->url, $b->depth]);
        self::assertSame(['https://shop.test/a', 1], [$a->url, $a->depth]);
        $pending = [$a, $b];
        $repo->savePage($audit, $pending[0], new PageResult('done', 404, 'text/html', discovered: ['https://shop.test/b']));
        $repo->savePage($audit, $pending[1], new PageResult('done', 301, null, 'https://shop.test/a'));
        $stored = $repo->byId($audit->id);
        self::assertSame(3, $stored?->pagesCrawled);
        self::assertSame(3, $stored->pagesFound);
        self::assertSame([], $repo->pendingPages($audit->id, 10));
        $inbound = $repo->inboundCounts($audit->id);
        self::assertSame(1, $inbound[$a->id] ?? null);
        self::assertSame(2, $inbound[$b->id] ?? null);
        self::assertCount(2, $inbound);
        self::assertSame(['https://shop.test/'], $repo->linkSources($audit->id, $pending[0]->id, 5));
        $byId = $repo->pagesById($audit->id, [$pending[1]->id, $home->id]);
        self::assertSame([301, 'https://shop.test/a'], [$byId[$pending[1]->id]->statusCode, $byId[$pending[1]->id]->redirectTo]);
        self::assertSame('https://shop.test/', $byId[$home->id]->url);
        self::assertCount(2, $byId);
        self::assertSame([], $repo->pagesById($audit->id, []));

        $repo->finish($audit->id, 'completed', 50, ['error' => 1, 'warning' => 0, 'notice' => 1], [
            ['pageId' => $pending[0]->id, 'type' => IssueType::BrokenPage, 'detail' => 'HTTP 404'],
            ['pageId' => $pending[1]->id, 'type' => IssueType::Redirect, 'detail' => null],
        ], null);
        $done = $repo->byId($audit->id);
        self::assertSame('completed', $done?->status);
        self::assertSame(50, $done->healthScore);
        self::assertSame(1, $done->errors);
        self::assertSame(['broken_page' => 1, 'redirect' => 1], $repo->issueCounts($audit->id));
        $issues = $repo->issues($audit->id, null, 0, 10);
        self::assertSame([IssueType::BrokenPage, IssueType::Redirect], array_column($issues, 'type'));
        self::assertSame('https://shop.test/a', $issues[0]['url']);
        self::assertCount(1, $repo->issues($audit->id, IssueType::Redirect, 0, 10));
        self::assertSame(['https://shop.test/', 'https://shop.test/a', 'https://shop.test/b'], array_map(static fn(AuditPage $p): string => $p->url, $repo->pages($audit->id, 0, 10)));
        self::assertSame(404, $repo->pages($audit->id, 1, 1)[0]->statusCode);

        // Newer audits push the oldest finished ones out.
        for ($i = 0; $i < 3; $i++) {
            $next = $repo->create($this->projectId, $this->workspaceId, 'https://shop.test/', 3, 'scheduled', null);
            $repo->finish($next->id, 'cancelled', null, ['error' => 0, 'warning' => 0, 'notice' => 0], [], null);
        }
        $repo->prune($this->projectId, 2);
        self::assertNull($repo->byId($audit->id));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM site_audit_pages WHERE audit_id = ?', [$audit->id]));
        self::assertCount(2, $repo->history($this->projectId, 10));
        self::assertSame('cancelled', $repo->latest($this->projectId)?->status);
        self::assertNull($repo->latest($this->projectId, 'completed'));
    }

    public function testCancelledAuditRejectsLatePageAndCompletionWrites(): void
    {
        $repo = $this->app->make(SiteAuditRepository::class);
        $audit = $repo->create($this->projectId, $this->workspaceId, 'https://shop.test/', 3, 'manual', null);
        $page = $repo->pendingPages($audit->id, 1)[0];
        $repo->finish($audit->id, 'cancelled', null, ['error' => 0, 'warning' => 0, 'notice' => 0], [], null);
        $repo->savePage($audit, $page, new PageResult('done', 200, 'text/html', discovered: ['https://shop.test/late']));
        $repo->finish($audit->id, 'completed', 100, ['error' => 0, 'warning' => 0, 'notice' => 0], [], null);
        self::assertSame('cancelled', $repo->byId($audit->id)?->status);
        self::assertSame([], $repo->allPages($audit->id));
    }

    public function testCrawlsASiteEndToEnd(): void
    {
        $http = new FakeHttpClient();
        $html = static fn(string $title, string $links): HttpResponse => new HttpResponse(200, '<html><head><title>' . $title . '</title></head><body><h1>' . $title . '</h1>' . $links . '</body></html>', headers: ['content-type' => 'text/html'], durationMs: 50);
        $http->on('GET', 'https://shop.test/robots.txt', static fn(): HttpResponse => new HttpResponse(404, ''));
        $http->on('GET', 'https://shop.test/', static fn(): HttpResponse => $html('Home of the shop', '<a href="/a">a</a><a href="/b">b</a>'));
        $http->on('GET', 'https://shop.test/a', static fn(): HttpResponse => $html('Home of the shop', '<a href="/">home</a><a href="/gone">x</a>'));
        $http->on('GET', 'https://shop.test/b', static fn(): HttpResponse => new HttpResponse(302, '', headers: ['location' => 'https://shop.test/a']));
        $http->on('GET', 'https://shop.test/gone', static fn(): HttpResponse => new HttpResponse(410, ''));
        $this->app->instance(HttpClient::class, $http);
        $this->app->instance(HostResolver::class, new FakeHostResolver());
        $repo = $this->app->make(SiteAuditRepository::class);
        $audit = $repo->create($this->projectId, $this->workspaceId, 'https://shop.test/', 100, 'manual', null);

        $job = new \SeoControlCenter\Modules\SiteAudit\Jobs\CrawlSiteAuditJob($this->app->make(SiteAuditService::class), $this->db, $this->app->make(\SeoControlCenter\Core\Queue\Queue::class));
        $job->handle(['audit_id' => $audit->id, 'workspace_id' => $this->workspaceId], new \SeoControlCenter\Core\Queue\JobContext('test', 'site_audit.crawl', 1, 3, static fn(): bool => true, time() + 50));

        $done = $repo->byId($audit->id);
        self::assertSame('completed', $done?->status);
        self::assertSame(4, $done->pagesCrawled);
        $counts = $repo->issueCounts($audit->id);
        self::assertSame(1, $counts['broken_page'] ?? 0);
        self::assertSame(1, $counts['redirect'] ?? 0);
        self::assertSame(2, $counts['duplicate_title'] ?? 0);
        self::assertNotNull($done->healthScore);
    }

    public function testPageSignalsRoundTripAndLegacyPagesStayUnknown(): void
    {
        $repo = $this->app->make(SiteAuditRepository::class);
        $audit = $repo->create($this->projectId, $this->workspaceId, 'https://shop.test/', 5, 'manual', null);
        [$home] = $repo->pendingPages($audit->id, 10);
        $html = \SeoControlCenter\Modules\SiteAudit\Crawler\HtmlPage::parse('<html><head><meta property="og:type" content="article"><script type="application/ld+json">{"@type":"Article","author":"Ana"}</script></head><body><p>Text</p><a href="tel:+44 33 123 456">Call</a></body></html>', 'https://shop.test/');
        $repo->savePage($audit, $home, new PageResult('done', 200, 'text/html', page: $html, discovered: ['https://shop.test/plain']));
        [$plain] = $repo->pendingPages($audit->id, 10);
        $repo->savePage($audit, $plain, new PageResult('done', 404, 'text/html'));
        $pages = $repo->pagesById($audit->id, [$home->id, $plain->id]);
        self::assertSame([['Article'], 'article', true], [$pages[$home->id]->schemaTypes, $pages[$home->id]->ogType, $pages[$home->id]->hasAuthor]);
        self::assertTrue($pages[$home->id]->isArticle());
        self::assertSame([0, null], [$pages[$home->id]->sourceLinks, $pages[$plain->id]->sourceLinks], 'Source links are counted for parsed pages and unknown otherwise (0.42.0).');
        self::assertSame([null, null, null], [$pages[$plain->id]->schemaTypes, $pages[$plain->id]->ogType, $pages[$plain->id]->hasAuthor], 'Pages without parsed HTML keep unknown signals.');
        self::assertSame(['questions' => 0, 'tables' => 0, 'lists' => 0, 'figures' => 0, 'lead_words' => null, 'updated' => false], $pages[$home->id]->citation, 'Citation signals round-trip (0.43.0).');
        self::assertNull($pages[$plain->id]->citation);
        self::assertNull($repo->findPage($audit->id, 'https://shop.test/plain')?->citation);
        self::assertSame(['types' => [], 'name' => null, 'phones' => ['+4433123456'], 'address' => null], $pages[$home->id]->business, 'Business details round-trip (0.46.0).');
        self::assertNull($pages[$plain->id]->business);
    }
}
