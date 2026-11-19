<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Backlinks\PageBacklink;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Modules\SiteAudit\AuditPage;
use SeoControlCenter\Modules\SiteAudit\SiteAudit;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankings;
use SeoControlCenter\Tests\Support\Fakes\InMemoryResearch;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Fakes\InMemorySiteAudit;
use SeoControlCenter\Tests\Support\Scenario;

/** Titles Google rewrites, orphan pages and pages to prune (1.7.0). */
final class PageCleanupHttpTest extends TestCase
{
    private Scenario $s;
    private InMemorySiteAudit $audits;
    private InMemorySearchPerformance $search;

    protected function setUp(): void
    {
        $this->s = new Scenario([], new FrozenClock(new \DateTimeImmutable('2026-11-19 08:00:00', new \DateTimeZone('UTC'))));
        $audits = $this->s->container->get(InMemorySiteAudit::class);
        $search = $this->s->container->get(InMemorySearchPerformance::class);
        self::assertInstanceOf(InMemorySiteAudit::class, $audits);
        self::assertInstanceOf(InMemorySearchPerformance::class, $search);
        $this->audits = $audits;
        $this->search = $search;
    }

    public function testTitlesGoogleRewrites(): void
    {
        $project = $this->s->acmeProject->id;
        $url = '/projects/' . $this->s->acmeProject->publicId . '/site-audit/title-rewrites';
        $viewer = $this->s->loggedIn($this->s->viewer);
        self::assertStringContainsString('No titles recorded yet', (string) $viewer->get($url)->getBody());

        $keywords = $this->s->container->get(InMemoryKeywords::class);
        $rankings = $this->s->container->get(InMemoryRankings::class);
        self::assertInstanceOf(InMemoryKeywords::class, $keywords);
        self::assertInstanceOf(InMemoryRankings::class, $rankings);
        $keywords->add($project, ['red shoes', 'buy red shoes', 'blue boots', 'acme shop', 'old title', 'elsewhere'], KeywordLocale::default(), $this->s->owner->id);
        $ids = array_column($keywords->keywords[$project], 'id', 'keyword');
        $snap = static fn(string $keyword, string $date, string $page, string $title): RankSnapshot => new RankSnapshot($ids[$keyword], $project, SerpDevice::Desktop, $date, 3, 3, 'https://shop.acme.test' . $page, [], [], shownTitle: $title);
        $rankings->storeSnapshot($snap('red shoes', '2026-11-18', '/red/', 'Red shoes for every day – Acme'));
        $rankings->storeSnapshot($snap('buy red shoes', '2026-11-18', '/red', 'Red shoes | Acme'));
        $rankings->storeSnapshot($snap('blue boots', '2026-11-18', '/blue', 'Blue boots | Acme'));
        $rankings->storeSnapshot($snap('acme shop', '2026-11-18', '/', 'Acme Shop: shoes and boots'));
        $rankings->storeSnapshot($snap('old title', '2026-07-01', '/blue', 'Too old to count'));
        $rankings->storeSnapshot($snap('elsewhere', '2026-11-18', '/elsewhere', 'Not audited'));
        $rankings->storeSnapshot(new RankSnapshot($ids['red shoes'], $project, SerpDevice::Mobile, '2026-11-18', 3, 3, 'https://shop.acme.test/red', [], [], shownTitle: 'Mobile is not the tracked device'));
        self::assertStringContainsString('Run a site audit first', (string) $viewer->get($url)->getBody());

        $this->auditWith([
            ['https://shop.acme.test/', 0, 'Home'],
            ['https://shop.acme.test/red', 1, 'Red shoes | Acme'],
            ['https://shop.acme.test/blue', 1, 'Blue boots | Acme'],
        ]);
        $html = (string) $viewer->get($url)->getBody();
        self::assertMatchesRegularExpression('#Titles compared</h2><p class="stat-value">4</p><p class="stat-note">on 3 page\(s\)#', $html);
        self::assertMatchesRegularExpression('#Shown as written</h2><p class="stat-value">2</p>#', $html, '/red for "buy red shoes" and /blue.');
        self::assertMatchesRegularExpression('#<td>Home</td>\s*<td>Acme Shop: shoes and boots</td>\s*<td>Rewritten<br><span class="muted small">0 % of its words are yours#', $html);
        self::assertStringNotContainsString('Too old to count', $html);
        self::assertStringNotContainsString('Mobile is not the tracked device', $html);
        self::assertStringContainsString('1 ranking page(s) were not in the latest audit', $html);

        $rankings->storeSnapshot($snap('red shoes', '2026-11-19', '/red/', 'Red shoes for every day – Acme'));
        $rewritten = (string) $viewer->get($url . '?kind=rewritten')->getBody();
        self::assertStringContainsString('Red shoes for every day – Acme', $rewritten, 'Google shows another title for another search on the same page.');
        self::assertStringContainsString('red shoes<br><span class="muted">checked 2026-11-19', $rewritten);
        self::assertStringContainsString('No titles of this kind.', (string) $viewer->get($url . '?kind=cut')->getBody());
        self::assertSame(404, $viewer->get('/projects/' . $this->s->rivalProject->publicId . '/site-audit/title-rewrites')->getStatusCode());
        self::assertStringContainsString('/site-audit/title-rewrites', (string) $viewer->get('/projects/' . $this->s->acmeProject->publicId . '/site-audit')->getBody());
    }

    public function testOrphanPages(): void
    {
        $viewer = $this->s->loggedIn($this->s->viewer);
        $url = '/projects/' . $this->s->acmeProject->publicId . '/site-audit/orphans';
        self::assertStringContainsString('Run a site audit first', (string) $viewer->get($url)->getBody());

        $this->auditWith([
            ['https://shop.acme.test/', 0, 'Home'],
            ['https://shop.acme.test/linked', 1, 'Linked'],
            ['https://shop.acme.test/sitemap-only', AuditPage::UNLINKED, 'Sitemap only'],
            ['https://shop.acme.test/sitemap-quiet', AuditPage::UNLINKED, 'Sitemap, no searches'],
        ]);
        self::assertStringContainsString('Connect Search Console first', (string) $viewer->get($url)->getBody());
        $this->searchDays('2026-08-01', '2026-11-15', [
            'https://shop.acme.test/' => new Metrics(10, 100, 2.0),
            'https://shop.acme.test/linked/' => new Metrics(5, 50, 4.0),
            'https://shop.acme.test/sitemap-only' => new Metrics(2, 40, 8.0),
            'https://shop.acme.test/forgotten' => new Metrics(1, 30, 12.0),
            'http://shop.acme.test/forgotten' => new Metrics(1, 30, 12.0),
            'https://blog.acme.test/post' => new Metrics(1, 30, 12.0),
        ]);

        $html = (string) $viewer->get($url)->getBody();
        self::assertMatchesRegularExpression('#Pages with impressions</h2><p class="stat-value">4</p>#', $html, 'Other hosts and schemes are left out.');
        self::assertMatchesRegularExpression('#Orphan pages</h2><p class="stat-value">2</p>#', $html);
        self::assertMatchesRegularExpression('#Unlinked, no impressions</h2><p class="stat-value">1</p>#', $html);
        self::assertMatchesRegularExpression('#sitemap-only</a></td>\s*<td>Only in the sitemap</td>\s*<td>180</td>.*/forgotten</a></td>\s*<td>Not linked, not in the sitemap</td>#s', $html);
        self::assertStringNotContainsString('/linked/', $html, 'A trailing slash is the same page.');
        self::assertStringNotContainsString('blog.acme.test', $html);
        self::assertStringNotContainsString('stopped at its page limit', $html);
        self::assertSame(404, $viewer->get('/projects/' . $this->s->rivalProject->publicId . '/site-audit/orphans')->getStatusCode());

        // An audit cut by its page limit cannot tell pages it never reached from orphans.
        $this->auditWith([['https://shop.acme.test/', 0, 'Home'], ['https://shop.acme.test/sitemap-only', AuditPage::UNLINKED, 'Sitemap only']], maxPages: 1);
        $limited = (string) $viewer->get($url)->getBody();
        self::assertStringContainsString('stopped at its page limit', $limited);
        self::assertMatchesRegularExpression('#/forgotten</a></td>\s*<td>Not reached \(page limit\)</td>#', $limited);
    }

    public function testPagesToPrune(): void
    {
        $viewer = $this->s->loggedIn($this->s->viewer);
        $url = '/projects/' . $this->s->acmeProject->publicId . '/site-audit/prune';
        $this->auditWith([['https://shop.acme.test/', 0, 'Home'], ['https://shop.acme.test/old-news', 1, 'Old news']]);
        $this->searchDays('2026-10-01', '2026-11-15', ['https://shop.acme.test/' => new Metrics(10, 100, 2.0)]);
        self::assertStringContainsString('Not enough Search Console data yet', (string) $viewer->get($url)->getBody());

        $this->searchDays('2025-11-01', '2026-11-15', ['https://shop.acme.test/' => new Metrics(10, 100, 2.0), 'https://shop.acme.test/searched' => new Metrics(0, 1, 40.0)]);
        $this->auditWith([
            ['https://shop.acme.test/', 0, 'Home'],
            ['https://shop.acme.test/searched', 1, 'Searched'],
            ['https://shop.acme.test/old-news', 1, 'Old news', 120],
            ['https://shop.acme.test/guide', 1, 'Guide', 900],
            ['https://shop.acme.test/linked-from-outside', 1, 'Popular once', 80],
        ]);
        $research = $this->s->container->get(InMemoryResearch::class);
        self::assertInstanceOf(InMemoryResearch::class, $research);
        $html = (string) $viewer->get($url)->getBody();
        self::assertStringContainsString('SEOcc has only one audit', $html);
        self::assertStringContainsString('No backlink sample is stored', $html);
        self::assertMatchesRegularExpression('#No impressions</h2><p class="stat-value">3</p>#', $html);

        $project = $this->s->acmeProject;
        $research->queue($project->id, $project->workspaceId, 'bl_links', '', ['domain' => 'shop.acme.test'], null);
        $research->complete($project->id, 'bl_links', '', [(new PageBacklink('https://news.test/a', 'https://shop.acme.test/linked-from-outside/', 'Acme', 40, true, '2024-01-01', null))->toArray()]);
        // A newer audit with an extra page: pages missing from the oldest kept audit are new.
        $this->auditWith([
            ['https://shop.acme.test/', 0, 'Home'],
            ['https://shop.acme.test/old-news', 1, 'Old news', 120],
            ['https://shop.acme.test/guide', 1, 'Guide', 900],
            ['https://shop.acme.test/linked-from-outside', 1, 'Popular once', 80],
            ['https://shop.acme.test/just-published', 1, 'New', 900],
            ['https://shop.acme.test/blog?page=2', 1, 'Page 2', 50],
        ], keepEarlier: true);
        $html = (string) $viewer->get($url)->getBody();
        self::assertMatchesRegularExpression('#old-news</a>.*Merge or remove.*/guide</a>.*Improve or noindex.*/linked-from-outside</a>.*Redirect#s', $html);
        self::assertStringNotContainsString('just-published</a>', $html);
        self::assertStringContainsString('1 page(s) that were not in the audit of', $html);
        self::assertStringNotContainsString('page=2', $html, 'Later listing pages are not candidates.');
        self::assertStringNotContainsString('/searched', $html);

        $csv = (string) $viewer->get($url . '?format=csv')->getBody();
        self::assertStringContainsString('https://shop.acme.test/old-news', $csv);
        self::assertStringContainsString('Merge or remove', $csv);
        self::assertStringNotContainsString('just-published', $csv);
        self::assertSame(404, $viewer->get('/projects/' . $this->s->rivalProject->publicId . '/site-audit/prune')->getStatusCode());
    }

    /**
     * Replaces the audits with one completed audit of these pages (url, depth, title, words).
     *
     * @param list<array{0: string, 1: int, 2: string, 3?: int}> $pages
     */
    private function auditWith(array $pages, int $maxPages = 100, bool $keepEarlier = false): SiteAudit
    {
        if (!$keepEarlier) {
            $this->audits->audits = [];
        }
        $audit = $this->audits->create($this->s->acmeProject->id, $this->s->acme->id, 'https://shop.acme.test/', $maxPages, 'manual', null);
        $first = $this->audits->pendingPages($audit->id, 1)[0]->id;
        $this->audits->pages[$audit->id] = [];
        foreach ($pages as $i => $page) {
            $this->audits->pages[$audit->id][$first + $i] = new AuditPage($first + $i, $page[0], $page[1], 'done', 200, 'text/html', title: $page[2], wordCount: $page[3] ?? 500);
        }
        $this->audits->finish($audit->id, 'completed', 90, ['error' => 0, 'warning' => 0, 'notice' => 0], [], null);

        return $this->audits->byId($audit->id) ?? self::fail('Audit lost.');
    }

    /** @param array<string, Metrics> $pages daily metrics per page URL */
    private function searchDays(string $from, string $to, array $pages): void
    {
        $project = $this->s->acmeProject->id;
        $this->search->daily[$project] = [];
        $this->search->details[$project] = [];
        for ($day = new \DateTimeImmutable($from); $day <= new \DateTimeImmutable($to); $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $this->search->daily[$project][$date] = new Metrics(1, 10, 5.0);
            $this->search->details[$project][$date]['page'] = array_map(static fn(string $page, Metrics $m): DimensionRow => new DimensionRow($page, $m), array_keys($pages), array_values($pages));
        }
    }
}
