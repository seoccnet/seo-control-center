<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Clusters\ClusterRepository;
use SeoControlCenter\Domain\InternalLinks\LinkGraphRepository;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Modules\SiteAudit\Crawler\HtmlPage;
use SeoControlCenter\Modules\SiteAudit\LinkSuggestionService;
use SeoControlCenter\Modules\SiteAudit\PageResult;
use SeoControlCenter\Modules\SiteAudit\SiteAudit;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;

/**
 * ADR-040, F1: anchors, places, link paragraphs and page paragraphs stored
 * while crawling, suggestions built from owned topics, text kept only for
 * the newest audit, all bound to one project.
 */
final class LinkGraphPersistenceTest extends FullStackTestCase
{
    private const array SITE = [
        'https://shop.test/' => '<html><body><nav><a href="/sunflower-seeds">Sunflowers</a><a href="/blog/outdoor">Blog</a></nav><main><p>Welcome to the shop. We ship discreetly across Europe, and every order includes free seeds.</p></main><footer><a href="/blog/outdoor">Outdoor guide</a></footer></body></html>',
        'https://shop.test/sunflower-seeds' => '<html><body><nav><a href="/">Home</a></nav><main><p>Our sunflower seeds finish in ten weeks and suit beginners, balconies and short northern summers.</p></main></body></html>',
        'https://shop.test/blog/topics/sunflower-seeds' => '<html><body><main><p>All our posts about sunflower seeds, newest first, with short summaries for every article.</p></main></body></html>',
        'https://shop.test/blog/outdoor?page=2' => '<html><body><main><p>Most outdoor growers in the north start with Sunflower Seeds because the plants finish before autumn rain.</p></main></body></html>',
        'https://shop.test/blog/outdoor' => '<html><body><nav><a href="/">Home</a><a href="/sunflower-seeds">Shop</a></nav><main><p>Most outdoor growers in the north start with Sunflower Seeds because the plants finish before autumn rain.</p><p>Read our <a href="/">shop page</a> for the current cultivars and offers available this season.</p></main></body></html>',
    ];

    public function testCrawlStoresContextAndSuggestionsFollowOwnedTopics(): void
    {
        $owner = $this->createUser('links@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace']->id;
        $id = '0192f0a0-0000-7000-8000-000000000301';
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$id, $workspace]);
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($workspace, $id);
        self::assertNotNull($project);
        $audits = $this->app->make(SiteAuditRepository::class);
        $graph = $this->app->make(LinkGraphRepository::class);

        $first = $this->crawl($audits, $project->id, $workspace);
        $blog = $audits->findPage($first->id, 'https://shop.test/blog/outdoor');
        $home = $audits->findPage($first->id, 'https://shop.test/');
        self::assertNotNull($blog);
        self::assertNotNull($home);
        $out = $graph->pageLinks($first->id, $blog->id, 'out', 50);
        self::assertSame([
            ['url' => 'https://shop.test/', 'anchor' => 'shop page', 'place' => 'body', 'class' => 'editorial', 'confidence' => 'high', 'context' => 'Read our shop page for the current cultivars and offers available this season.'],
            ['url' => 'https://shop.test/sunflower-seeds', 'anchor' => 'Shop', 'place' => 'nav', 'class' => 'template', 'confidence' => 'high', 'context' => null],
        ], $out, 'The body link to the home page wins over the menu link and keeps its paragraph; body links come first.');
        $in = $graph->pageLinks($first->id, $blog->id, 'in', 50);
        self::assertSame([['url' => 'https://shop.test/', 'anchor' => 'Blog', 'place' => 'nav', 'class' => 'template', 'confidence' => 'high', 'context' => null]], $in, 'The first link on the home page is in the menu.');
        self::assertCount(6, $graph->paragraphs($first->id));
        self::assertTrue($graph->hasLinkText($first->id));
        self::assertTrue($graph->linked($first->id)[$blog->id . ':' . $home->id] ?? false);
        self::assertArrayNotHasKey($home->id . ':' . $blog->id, $graph->linked($first->id), 'A menu link does not count as linked.');
        // A related-article card in the body does not count as a link in the text either (0.35.0).
        $this->db->execute("UPDATE site_audit_links SET link_class = 'related' WHERE audit_id = ? AND from_page_id = ? AND to_page_id = ?", [$first->id, $blog->id, $home->id]);
        self::assertArrayNotHasKey($blog->id . ':' . $home->id, $graph->linked($first->id), 'A card link leaves room for a paragraph link.');
        $this->db->execute("UPDATE site_audit_links SET link_class = 'editorial' WHERE audit_id = ? AND from_page_id = ? AND to_page_id = ?", [$first->id, $blog->id, $home->id]);
        self::assertArrayHasKey('title', $graph->graphPages($first->id)[$home->id], 'Pages carry their title for the intent check.');
        self::assertNull($graph->graphPages($first->id)[$home->id]['title'] ?? null, 'The test site has no <title>.');
        self::assertSame([], $graph->pageLinks($first->id + 1000, $blog->id, 'out', 50));

        // A topic owned by the sunflower page: the blog mentions it without linking.
        $this->app->make(ClusterRepository::class)->sync($project->id, $workspace, [[
            'public_id' => '0192f0a0-0000-7000-8000-0000000003c1', 'label' => 'sunflower seeds', 'size' => 2, 'demand' => 900.0,
            'members' => [['keyword' => 'sunflower seeds', 'demand' => 800.0], ['keyword' => 'sunflowering seeds', 'demand' => 100.0]],
            'status' => 'owned', 'owner_url' => 'https://shop.test/sunflower-seeds', 'owner_source' => 'search_console', 'inferred_url' => 'https://shop.test/sunflower-seeds',
            'owner_reason' => 'Gets all clicks.', 'candidates' => [], 'overlap' => [], 'overlap_flagged' => 0,
        ]], '2026-10-17 10:00:00');
        $service = $this->app->make(LinkSuggestionService::class);
        self::assertNull($graph->builtAt($first->id));
        self::assertSame(2, $service->build($first->id), 'The blog only links to the topic owner from its menu, so the suggestion stays; its paginated copy (?page=2) is no source; the tag page /blog/topics/sunflower-seeds is a source, not the page for the search.');
        self::assertNotNull($graph->builtAt($first->id));
        self::assertSame(0, $graph->leftOut($first->id), 'No match was left out for another purpose.');
        self::assertNotNull($service->suggestions($project, '', 1)['built_at']);
        $listing = $service->suggestions($project, 'outdoor', 1);
        self::assertSame(1, $listing['total']);
        self::assertSame(2, $service->suggestions($project, '', 1)['total']);
        self::assertSame(['https://shop.test/blog/outdoor', 'https://shop.test/sunflower-seeds', 'Sunflower Seeds', 'sunflower seeds'], [$listing['suggestions'][0]['source_url'], $listing['suggestions'][0]['target_url'], $listing['suggestions'][0]['anchor'], $listing['suggestions'][0]['topic']]);
        self::assertSame(0, $service->suggestions($project, 'socks', 1)['total']);
        self::assertSame(2, $service->suggestions($project, 'blog', 1)['total']);
        self::assertSame(0, $graph->countSuggestions($project->id + 1000, $first->id, ''), 'Never through another project.');
        self::assertTrue($service->pageLinks($project, 'https://shop.test/blog/outdoor')['found']);
        self::assertFalse($service->pageLinks($project, 'https://shop.test/missing')['found']);

        // A newer audit: the old one keeps its links but loses its text and suggestions.
        $second = $this->crawl($audits, $project->id, $workspace);
        $graph->pruneText($project->id, $second->id);
        self::assertSame([], $graph->paragraphs($first->id));
        self::assertSame(0, $graph->countSuggestions($project->id, $first->id, ''));
        self::assertNotSame([], $graph->linked($first->id));
        self::assertCount(6, $graph->paragraphs($second->id));
        self::assertSame(0, $service->build($first->id), 'Only the newest completed audit is built.');
        self::assertSame([['audit_id' => $second->id, 'project_id' => $project->id, 'workspace_id' => $workspace]], array_values(array_filter($graph->latestCompleted(), static fn(array $r): bool => $r['project_id'] === $project->id)));

        // An audit crawled before 0.25.0 has links without places.
        $this->db->execute('UPDATE site_audit_links SET place = NULL, anchor = NULL WHERE audit_id = ?', [$first->id]);
        self::assertFalse($graph->hasLinkText($first->id));
    }

    private function crawl(SiteAuditRepository $audits, int $projectId, int $workspaceId): SiteAudit
    {
        $audit = $audits->create($projectId, $workspaceId, 'https://shop.test/', 100, 'manual', null);
        $audits->seed($audit, array_keys(self::SITE), 100);
        foreach ($audits->pendingPages($audit->id, 100) as $page) {
            $html = HtmlPage::parse(self::SITE[$page->url], $page->url);
            $audits->savePage($audit, $page, new PageResult('done', 200, 'text/html', durationMs: 5, bytes: 100, page: $html, internalLinks: count($html->links), discovered: $html->links));
        }
        $audits->finish($audit->id, 'completed', 90, ['error' => 0, 'warning' => 0, 'notice' => 0], [], null);

        return $audits->byId($audit->id) ?? self::fail('Audit missing.');
    }
}
