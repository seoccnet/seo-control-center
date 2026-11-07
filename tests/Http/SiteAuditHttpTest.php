<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Opportunities\OpportunityAnalyzer;
use SeoControlCenter\Modules\Reports\ReportBuilder;
use SeoControlCenter\Modules\Reports\ReportText;
use SeoControlCenter\Modules\SiteAudit\SiteAudit;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;
use SeoControlCenter\Modules\SiteAudit\SiteAuditScheduler;
use SeoControlCenter\Modules\SiteAudit\SiteAuditService;
use SeoControlCenter\Tests\Support\Fakes\FakeHostResolver;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankings;
use SeoControlCenter\Tests\Support\Fakes\InMemorySiteAudit;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Site audit: starting, crawling a simulated site, the issue report, the
 * page list and CSV, settings, weekly audits, permissions and isolation.
 */
final class SiteAuditHttpTest extends TestCase
{
    public function testDeletedProjectAndCancellationStopNewFetches(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url());
        $audit = $this->latest();
        $this->s->dir->deleted[$this->s->acmeProject->id] = true;
        self::assertTrue($this->service()->crawl($audit->id, static fn(): bool => true));
        self::assertSame('cancelled', $this->latest()->status);
        self::assertSame([], $this->http->requests);
        unset($this->s->dir->deleted[$this->s->acmeProject->id]);
        $owner->post($this->url());
        $audit = $this->latest();
        self::assertTrue($this->service()->crawl($audit->id, function () use ($audit): bool {
            $this->audits->finish($audit->id, 'cancelled', null, ['error' => 0, 'warning' => 0, 'notice' => 0], [], null);
            return true;
        }));
        self::assertSame('cancelled', $this->latest()->status);
        self::assertSame([], array_values(array_filter($this->http->requests, static fn(array $request): bool => $request['url'] === 'https://shop.acme.test/')));
    }
    private Scenario $s;

    private FakeHttpClient $http;

    private InMemorySiteAudit $audits;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-03 10:00:00', new \DateTimeZone('UTC')));
        $this->s->container->instance(Clock::class, $clock);
        $this->audits = new InMemorySiteAudit($clock);
        $this->s->container->instance(InMemorySiteAudit::class, $this->audits);
        $this->s->container->instance(SiteAuditRepository::class, $this->audits);
        $this->s->container->instance(SiteAuditScheduler::class, $this->audits);
        $http = $this->s->container->get(HttpClient::class);
        self::assertInstanceOf(FakeHttpClient::class, $http);
        $this->http = $http;
        $this->site();
    }

    public function testCrawlsTheSiteAndReportsIssues(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $base = $this->url();
        self::assertStringContainsString('No audit yet', (string) $owner->get($base)->getBody());

        self::assertSame(303, $owner->post($base)->getStatusCode());
        self::assertCount(1, $this->audits->queuedCrawls);
        $running = (string) $owner->get($base)->getBody();
        self::assertStringContainsString('Waiting for cron', $running);
        self::assertStringContainsString('data-refresh-seconds', $running);
        self::assertContains('project.site_audit_started', $this->s->audit()->actions());

        self::assertTrue($this->service()->crawl($this->audits->queuedCrawls[0]['auditId'], static fn(): bool => true));
        $audit = $this->latest();
        self::assertSame('completed', $audit->status);
        self::assertSame(7, $audit->pagesCrawled);
        self::assertSame(['https://other.test/robots.txt', 'https://other.test/'], array_column($this->http->requestsTo('https://other.test'), 'url'), 'One bounded status probe; no external crawl or redirect following.');
        self::assertSame([], $this->http->requestsTo('https://shop.acme.test/admin'));

        $page = (string) $owner->get($base)->getBody();
        self::assertStringNotContainsString('data-refresh-seconds', $page);
        self::assertStringContainsString('Health score', $page);
        foreach (['Broken page (4xx)', 'Redirect', 'Linked over http://', 'Blocked by robots.txt', 'Missing title', 'Little text'] as $label) {
            self::assertStringContainsString($label, $page, $label);
        }
        self::assertStringContainsString('/missing', $page);
        self::assertStringContainsString('HTTP 404 · linked from 2 pages', $page);

        $broken = (string) $owner->get($base . '?type=broken_page')->getBody();
        self::assertStringContainsString('Links on your site lead to a page that does not exist', $broken);
        self::assertStringContainsString('e.g. on', $broken);
        self::assertStringNotContainsString('Linked over http://</span>', $broken);

        $pages = (string) $owner->get($base . '/' . $audit->publicId . '/pages')->getBody();
        self::assertStringContainsString('/tools', $pages);
        self::assertStringContainsString('→ /tools', $pages);
        self::assertStringContainsString('robots.txt', $pages);

        $csv = $owner->get($base . '/' . $audit->publicId . '/export');
        self::assertSame('text/csv; charset=utf-8', $csv->getHeaderLine('Content-Type'));
        self::assertStringContainsString('https://shop.acme.test/missing,"Broken page (4xx)",error,"HTTP 404 · linked from 2 pages"', (string) $csv->getBody());
    }

    public function testSitemapPagesAreCheckedAndOrphansReported(): void
    {
        $this->http->on('GET', 'https://shop.acme.test/sitemap.xml', static fn(): HttpResponse => new HttpResponse(200, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://shop.acme.test/tools</loc></url><url><loc>https://shop.acme.test/hidden</loc></url></urlset>'));
        $this->http->on('GET', 'https://shop.acme.test/hidden', static fn(): HttpResponse => new HttpResponse(200, self::html('A page nobody links to', 'Hidden away.', 300), headers: ['content-type' => 'text/html']));
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url());
        $this->service()->crawl($this->latest()->id, static fn(): bool => true);

        $audit = $this->latest();
        self::assertSame(8, $audit->pagesCrawled);
        $hidden = null;
        foreach ($this->audits->allPages($audit->id) as $page) {
            if ($page->url === 'https://shop.acme.test/tools') {
                self::assertSame(1, $page->depth, 'a sitemap page that is linked gets its click depth');
            }
            if ($page->url === 'https://shop.acme.test/hidden') {
                $hidden = $page;
            }
        }
        self::assertFalse($hidden?->isLinked());
        $page = (string) $owner->get($this->url() . '?type=not_linked')->getBody();
        self::assertStringContainsString('Not linked internally', $page);
        self::assertStringContainsString('/hidden', $page);
        self::assertStringContainsString('title="Not reachable by links from the home page">—<', (string) $owner->get($this->url() . '/' . $audit->publicId . '/pages')->getBody());
    }

    public function testCompletedAuditFeedsOpportunities(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url());
        $this->service()->crawl($this->latest()->id, static fn(): bool => true);

        $rankings = $this->s->container->get(InMemoryRankings::class);
        self::assertInstanceOf(InMemoryRankings::class, $rankings);
        $rankings->projects[$this->s->acmeProject->id] = ['workspace_id' => $this->s->acme->id, 'domain' => 'shop.acme.test'];
        $analyzer = $this->s->container->get(OpportunityAnalyzer::class);
        $analyzer->analyze($this->s->acmeProject->id, $this->s->acme->id);
        $page = (string) $owner->get('/projects/' . $this->s->acmeProject->publicId . '/opportunities?type=site_issue')->getBody();
        self::assertStringContainsString('Broken page (4xx) on 1 page', $page);
        self::assertStringContainsString('Missing title on 1 page', $page);
        self::assertStringContainsString('/missing', $page);
        self::assertStringNotContainsString('Little text on', $page, 'notices stay in the audit');

        $report = (string) $owner->get('/projects/' . $this->s->acmeProject->publicId . '/reports/view?frequency=weekly')->getBody();
        self::assertStringContainsString('<h2>Site audit</h2>', $report);
        self::assertStringContainsString('7 pages · audit of 2026-10-03', $report);
        self::assertStringContainsString('Broken page (4xx): 1 page', $report);
        $builder = $this->s->container->get(ReportBuilder::class);
        $text = ReportText::body($builder->build($this->s->acmeProject, 'weekly', DateRange::endingOn('2026-10-03', 7)), 'https://scc.test/report');
        self::assertStringContainsString('SITE AUDIT (7 pages, 2026-10-03)', $text);
    }

    public function testSecondAuditIsComparedWithTheFirst(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url());
        $this->service()->crawl($this->latest()->id, static fn(): bool => true);
        $first = $this->latest();

        // The broken link is fixed before the second audit.
        $this->http->on('GET', 'https://shop.acme.test/missing', static fn(): HttpResponse => new HttpResponse(200, self::html('Missing no more', 'Found again.', 300), headers: ['content-type' => 'text/html']));
        $owner->get($this->url());
        $owner->post($this->url());
        $this->service()->crawl($this->latest()->id, static fn(): bool => true);
        $second = $this->latest();
        self::assertNotSame($first->id, $second->id);
        self::assertGreaterThan((int) $first->healthScore, (int) $second->healthScore);

        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('since 2026-10-03', $page);
        self::assertStringContainsString('<span class="delta delta-good">-1</span>', $page);
        $old = (string) $owner->get($this->url() . '/' . $first->publicId)->getBody();
        self::assertStringContainsString('an earlier audit', $old);
        self::assertStringContainsString('HTTP 404', $old);
    }

    public function testCrawlStopsWhenTimeRunsOutAndCanBeCancelled(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url());
        $audit = $this->latest();
        $calls = 0;
        self::assertFalse($this->service()->crawl($audit->id, static function () use (&$calls): bool {
            return ++$calls <= 2;
        }));
        self::assertSame('running', $this->latest()->status);
        self::assertSame(0, $this->latest()->pagesCrawled, 'Startup is bounded before page reads.');
        self::assertNotEmpty($this->audits->crawlState($audit->id));

        self::assertSame(409, $owner->post($this->url())->getStatusCode());
        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/' . $audit->publicId . '/cancel')->getStatusCode());
        self::assertSame('cancelled', $this->latest()->status);
        self::assertTrue($this->service()->crawl($audit->id, static fn(): bool => true));
        self::assertSame(0, $this->latest()->pagesCrawled);
    }

    public function testStalledCrawlIsMarkedFailed(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url());
        $this->audits->age($this->latest()->id, '2026-10-03 07:00:00');

        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('The crawl stopped making progress', $page);
        self::assertSame('failed', $this->latest()->status);
    }

    public function testSettingsAndWeeklyAudits(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        self::assertSame(422, $owner->post($this->url() . '/settings', ['max_pages' => '7'])->getStatusCode());
        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/settings', ['max_pages' => '100', 'weekly' => '1'])->getStatusCode());
        $settings = $this->audits->settings($this->s->acmeProject->id);
        self::assertSame(100, $settings?->maxPages);
        self::assertTrue($settings->weekly);

        $this->audits->projectPublicIds[$this->s->acmeProject->id] = $this->s->acmeProject->publicId;
        self::assertSame(1, $this->service()->startDue());
        self::assertSame('scheduled', $this->latest()->trigger);
        self::assertSame(100, $this->latest()->maxPages);
        self::assertSame(0, $this->service()->startDue(), 'an audit is running');

        $this->service()->crawl($this->latest()->id, static fn(): bool => true);
        self::assertSame(0, $this->service()->startDue(), 'the last audit is less than a week old');
        $this->audits->age($this->latest()->id, '2026-09-26 08:00:00', '2026-09-26 08:00:00');
        self::assertSame(1, $this->service()->startDue());
    }

    public function testOnlyPermittedUsersActAndOtherWorkspacesGetNotFound(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url());
        $audit = $this->latest();

        $viewer = $this->s->loggedIn($this->s->viewer);
        $page = (string) $viewer->get($this->url())->getBody();
        self::assertStringNotContainsString('Save settings', $page);
        self::assertStringNotContainsString('>Stop<', $page);
        foreach (['', '/settings', '/' . $audit->publicId . '/cancel'] as $path) {
            $viewer->get($this->url());
            self::assertSame(403, $viewer->post($this->url() . $path, ['max_pages' => '100'])->getStatusCode(), $path);
        }

        $rival = $this->s->loggedIn($this->s->rivalOwner);
        $rival->get('/');
        foreach (['', '/' . $audit->publicId, '/' . $audit->publicId . '/pages', '/' . $audit->publicId . '/export'] as $path) {
            self::assertSame(404, $rival->get($this->url() . $path)->getStatusCode(), $path);
        }
        foreach (['', '/settings', '/' . $audit->publicId . '/cancel'] as $path) {
            $rival->get('/');
            self::assertSame(404, $rival->post($this->url() . $path, ['max_pages' => '100'])->getStatusCode(), $path);
        }
        // An audit of another project is not found under this one.
        self::assertSame(404, $owner->get('/projects/' . $this->s->acmeOther->publicId . '/site-audit/' . $audit->publicId)->getStatusCode());
        self::assertSame('queued', $this->latest()->status);
    }

    public function testPrivateAddressesAreNotFetched(): void
    {
        $resolver = $this->s->container->get(FakeHostResolver::class);
        self::assertInstanceOf(FakeHostResolver::class, $resolver);
        $resolver->map['shop.acme.test'] = ['10.0.0.5'];
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url());
        $this->service()->crawl($this->latest()->id, static fn(): bool => true);

        self::assertSame([], $this->http->requests);
        self::assertSame('failed', $this->latest()->status);
        self::assertStringContainsString('Unsafe robots.txt destination', (string) $owner->get($this->url())->getBody());
    }

    private function site(): void
    {
        $html = static fn(string $body): HttpResponse => new HttpResponse(200, $body, headers: ['content-type' => 'text/html; charset=utf-8'], durationMs: 120);
        $this->http->on('GET', 'https://shop.acme.test/robots.txt', static fn(): HttpResponse => new HttpResponse(200, "User-agent: *\nDisallow: /admin\n"));
        $this->http->on('GET', 'https://www.shop.acme.test/robots.txt', static fn(): HttpResponse => new HttpResponse(404, ''));
        $this->http->on('GET', 'https://other.test/robots.txt', static fn(): HttpResponse => new HttpResponse(404, ''));
        $this->http->on('GET', 'https://shop.acme.test/', static fn(): HttpResponse => $html(self::html('Acme garden shop', 'Tools for every garden.', 300, [
            '/tools', '/old', '/missing', 'http://shop.acme.test/insecure', '/admin/users', 'https://other.test/', 'https://www.shop.acme.test/www', 'mailto:x@acme.test',
        ])));
        $this->http->on('GET', 'https://shop.acme.test/tools', static fn(): HttpResponse => $html(self::html('Garden tools at Acme', 'Spades and rakes.', 300, ['/', '/missing'])));
        $this->http->on('GET', 'https://shop.acme.test/old', static fn(): HttpResponse => new HttpResponse(301, '', headers: ['location' => '/tools']));
        $this->http->on('GET', 'https://shop.acme.test/missing', static fn(): HttpResponse => new HttpResponse(404, 'Not found', headers: ['content-type' => 'text/html']));
        $this->http->on('GET', 'https://www.shop.acme.test/www', static fn(): HttpResponse => $html('<html><body><h1>No title</h1><p>Short.</p></body></html>'));
    }

    /**
     * @param list<string> $links
     */
    private static function html(string $title, string $description, int $words, array $links = []): string
    {
        $anchors = implode('', array_map(static fn(string $href): string => '<a href="' . $href . '">link</a>', $links));

        return '<!doctype html><html><head><title>' . $title . '</title><meta name="description" content="' . $description . '"></head><body><h1>' . $title . '</h1><p>'
            . str_repeat('word ', $words) . '</p>' . $anchors . '</body></html>';
    }

    private function latest(): SiteAudit
    {
        $audit = $this->audits->latest($this->s->acmeProject->id);
        self::assertNotNull($audit);

        return $audit;
    }

    private function service(): SiteAuditService
    {
        return $this->s->container->get(SiteAuditService::class);
    }

    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/site-audit';
    }

    public function testTheLongTitleFilterShowsWhichTitlesCostClicks(): void
    {
        $s = new Scenario();
        $audits = $s->container->get(InMemorySiteAudit::class);
        self::assertInstanceOf(InMemorySiteAudit::class, $audits);
        $audit = $audits->create($s->acmeProject->id, $s->acmeProject->workspaceId, 'https://shop.acme.test/', 10, 'manual', null);
        $first = $audits->pendingPages($audit->id, 1)[0];
        $long = 'Strawberry <b> organic seeds from the best breeders, shipped carefully worldwide';
        $audits->pages[$audit->id] = [$first->id => new \SeoControlCenter\Modules\SiteAudit\AuditPage($first->id, 'https://shop.acme.test/tastes/strawberry', 0, 'done', 200, 'text/html', title: $long)];
        $audits->finish($audit->id, 'completed', 90, ['error' => 0, 'warning' => 1, 'notice' => 0], [
            ['pageId' => $first->id, 'type' => \SeoControlCenter\Modules\SiteAudit\IssueType::TitleTooLong, 'detail' => mb_strlen($long) . ' characters'],
        ], null);
        $owner = $s->loggedIn($s->owner);
        $path = '/projects/' . $s->acmeProject->publicId . '/site-audit';

        self::assertStringNotContainsString('Long titles worth fixing first', (string) $owner->get($path)->getBody(), 'only on the long-title filter');
        $body = (string) $owner->get($path . '?type=title_too_long')->getBody();
        self::assertStringContainsString('Import Google Search Console data to see which of these 1 long titles cost clicks', $body);

        $search = $s->container->get(\SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance::class);
        self::assertInstanceOf(\SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance::class, $search);
        $search->daily[$s->acmeProject->id]['2026-10-20'] = new \SeoControlCenter\Domain\SearchConsole\Metrics(10, 1000, 3);
        $search->details[$s->acmeProject->id]['2026-10-20'] = [
            'page' => [new \SeoControlCenter\Domain\SearchConsole\DimensionRow('https://shop.acme.test/tastes/strawberry', new \SeoControlCenter\Domain\SearchConsole\Metrics(10, 1000, 3.0))],
            'query' => [new \SeoControlCenter\Domain\SearchConsole\DimensionRow('strawberry seeds', new \SeoControlCenter\Domain\SearchConsole\Metrics(10, 1000, 3.0))],
        ];
        $body = (string) $owner->get($path . '?type=title_too_long')->getBody();
        self::assertStringContainsString('Long titles worth fixing first', $body);
        self::assertMatchesRegularExpression('#<td><code>/tastes/…</code></td><td class="num">1</td><td class="num">1</td><td class="num"><strong>\d+</strong></td>#', $body);
        self::assertStringContainsString('href="/projects/' . $s->acmeProject->publicId . '/pages/details?url=https%3A%2F%2Fshop.acme.test%2Ftastes%2Fstrawberry">/tastes/strawberry</a>', $body);
        self::assertStringContainsString('Strawberry &lt;b&gt; organic seeds from the best breeders, shipped…', $body, 'the title as cut at 60 characters, escaped');
        self::assertStringContainsString('your site’s own clicks by position', $body);
        self::assertSame(404, $s->loggedIn($s->rivalOwner)->get($path . '?type=title_too_long')->getStatusCode());
    }
}
