<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\AiVisibility\AiCrawlers;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Modules\AiVisibility\AiCrawlerService;
use SeoControlCenter\Modules\Analytics\Ga4Service;
use SeoControlCenter\Modules\SiteAudit\AuditPage;
use SeoControlCenter\Tests\Support\Fakes\FakeGa4Provider;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryAiCrawlers;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Fakes\InMemorySiteAudit;
use SeoControlCenter\Tests\Support\Ga4Fixture;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * AI visibility readiness (0.43.0): AI crawler access from robots.txt and
 * llms.txt, citation readiness of the top pages, and visits from AI
 * assistants in GA4.
 */
final class AiReadinessHttpTest extends TestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        $this->s = new Scenario([], new FrozenClock(new \DateTimeImmutable('2026-10-25 08:00:00')));
    }

    public function testCrawlerCheckReadsRobotsTxtPerCrawlerAndShowsBlockedSearchCrawlers(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('AI crawler access', $page);
        self::assertStringContainsString('Check now</button>', $page);

        $response = $owner->post($this->url() . '/crawlers/check');
        self::assertSame(303, $response->getStatusCode());
        self::assertStringEndsWith('/ai-visibility#ai-crawlers', $response->getHeaderLine('Location'));
        $crawlers = $this->s->container->get(InMemoryAiCrawlers::class);
        self::assertInstanceOf(InMemoryAiCrawlers::class, $crawlers);
        self::assertSame([['project_id' => $this->s->acmeProject->id, 'workspace_id' => $this->s->acme->id, 'project_public_id' => $this->s->acmeProject->publicId]], $crawlers->queued);

        $this->topPage('https://shop.acme.test/private/offer', 9);
        $http = $this->s->container->get(FakeHttpClient::class);
        self::assertInstanceOf(FakeHttpClient::class, $http);
        $http->on('GET', 'https://shop.acme.test/robots.txt', static fn(): HttpResponse => new HttpResponse(301, '', headers: ['location' => 'https://www.shop.acme.test/robots.txt']));
        $http->on('GET', 'https://www.shop.acme.test/robots.txt', static fn(): HttpResponse => new HttpResponse(200, "User-agent: *\nDisallow: /cart\n\nUser-agent: OAI-SearchBot\nDisallow: /\n\nUser-agent: GPTBot\nUser-agent: CCBot\nDisallow: /\n\nUser-agent: Applebot\nDisallow: /private/\n"));
        $http->on('GET', 'https://shop.acme.test/llms.txt', static fn(): HttpResponse => new HttpResponse(200, '<html>Page not found</html>', headers: ['content-type' => 'text/html; charset=utf-8']));
        $service = $this->s->container->get(AiCrawlerService::class);
        self::assertInstanceOf(AiCrawlerService::class, $service);

        $service->run($this->s->acmeProject->id, $this->s->rival->id, $this->s->acmeProject->publicId);
        self::assertSame([], $http->requests, 'a project is only checked in its own workspace');
        $service->run($this->s->acmeProject->id, $this->s->acme->id, $this->s->acmeProject->publicId);

        $check = $crawlers->latest($this->s->acmeProject->id);
        self::assertNotNull($check);
        self::assertSame('ok', $check->robotsState);
        self::assertSame('https://www.shop.acme.test/robots.txt', $check->robotsUrl, 'the redirect to the www host is followed');
        $states = array_column($check->agents, 'state', 'token');
        self::assertSame(AiCrawlers::BLOCKED, $states['OAI-SearchBot']);
        self::assertSame(AiCrawlers::BLOCKED, $states['GPTBot']);
        self::assertSame(AiCrawlers::BLOCKED, $states['CCBot']);
        self::assertSame(AiCrawlers::PARTIAL, $states['Applebot'], 'its group blocks a top Search Console page');
        self::assertSame(AiCrawlers::ALLOWED, $states['Applebot-Extended'], 'the Applebot group does not apply to Applebot-Extended');
        self::assertSame(AiCrawlers::ALLOWED, $states['Googlebot']);
        self::assertSame('missing', $check->llmsState, 'an HTML page is not an llms.txt file');
        self::assertSame(['OAI-SearchBot', 'Applebot'], array_column($check->blockedSearch(), 'token'));

        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('robots.txt keeps OAI-SearchBot, Applebot out.', $page);
        self::assertStringContainsString('Some top pages blocked', $page);
        self::assertStringContainsString('/private/offer', $page);
        self::assertStringContainsString('Training crawlers', $page);
        self::assertStringContainsString('Few published tests', $page, 'llms.txt carries the early label');
        self::assertStringContainsString('Check again</button>', $page);

        $viewer = $this->s->loggedIn($this->s->viewer);
        self::assertStringNotContainsString('Check again</button>', (string) $viewer->get($this->url())->getBody());
        self::assertSame(403, $viewer->post($this->url() . '/crawlers/check')->getStatusCode());
        $rival = $this->s->loggedIn($this->s->rivalOwner);
        $rival->get('/projects');
        self::assertSame(404, $rival->post($this->url() . '/crawlers/check')->getStatusCode());
        self::assertCount(1, $crawlers->queued);
    }

    public function testUnreadableRobotsTxtIsNotEvaluatedAndChecksAreQueuedWeekly(): void
    {
        $http = $this->s->container->get(FakeHttpClient::class);
        self::assertInstanceOf(FakeHttpClient::class, $http);
        $http->on('GET', 'https://shop.acme.test/robots.txt', static fn(): HttpResponse => new HttpResponse(503, 'busy'));
        $http->on('GET', 'https://shop.acme.test/llms.txt', static fn(): HttpResponse => new HttpResponse(200, "# Acme\n> Shoes\n", headers: ['content-type' => 'text/plain']));
        $service = $this->s->container->get(AiCrawlerService::class);
        self::assertInstanceOf(AiCrawlerService::class, $service);
        $crawlers = $this->s->container->get(InMemoryAiCrawlers::class);
        self::assertInstanceOf(InMemoryAiCrawlers::class, $crawlers);

        $owner = $this->s->loggedIn($this->s->owner);
        $quality = '/projects/' . $this->s->acmeProject->publicId . '/data-quality';
        $before = (string) $owner->get($quality)->getBody();
        self::assertStringContainsString('Not checked yet: the weekly check runs within a day', $before, 'finding 37: no check is not a robots.txt fault');
        self::assertStringNotContainsString('could not be read at the last check', $before);
        self::assertGreaterThanOrEqual(2, $service->dispatchDue(), 'every project is due before its first check');
        self::assertContains($this->s->acmeProject->id, array_column($crawlers->queued, 'project_id'));
        $service->run($this->s->acmeProject->id, $this->s->acme->id, $this->s->acmeProject->publicId);
        $check = $crawlers->latest($this->s->acmeProject->id);
        self::assertNotNull($check);
        self::assertSame('unreachable', $check->robotsState);
        self::assertSame([], $check->agents);
        self::assertSame('found', $check->llmsState);
        self::assertSame(15, $check->llmsBytes);
        $crawlers->queued = [];
        $service->dispatchDue();
        self::assertNotContains($this->s->acmeProject->id, array_column($crawlers->queued, 'project_id'), 'checked this week');

        self::assertStringContainsString('robots.txt could not be read at the last check, so no crawler was evaluated.', (string) $owner->get($quality)->getBody());
        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('robots.txt could not be read (HTTP 503)', $page);
        self::assertStringContainsString('Found (15 bytes).', $page);
    }

    public function testCitationReadinessOfTopPagesComesFromTheLatestSiteAudit(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        self::assertStringContainsString('No Search Console pages yet.', (string) $owner->get($this->url())->getBody());

        $this->topPage('https://shop.acme.test/guide/', 40);
        $this->topPage('https://shop.acme.test/shoes', 12);
        $this->topPage('https://shop.acme.test/blog/lacing', 5);
        $this->topPage('https://shop.acme.test/seeds/legacy', 4);
        $this->topPage('https://shop.acme.test/old', 3);
        $audits = $this->s->container->get(InMemorySiteAudit::class);
        self::assertInstanceOf(InMemorySiteAudit::class, $audits);
        $audit = $audits->create($this->s->acmeProject->id, $this->s->acme->id, 'https://shop.acme.test/', 10, 'manual', null);
        $first = $audits->pendingPages($audit->id, 1)[0];
        $audits->pages[$audit->id] = [
            $first->id => new AuditPage(
                $first->id,
                'https://shop.acme.test/guide',
                0,
                'done',
                200,
                'text/html',
                wordCount: 900,
                schemaTypes: ['Article', 'BreadcrumbList'],
                hasAuthor: true,
                sourceLinks: 2,
                citation: ['questions' => 2, 'tables' => 1, 'lists' => 0, 'figures' => 3, 'lead_words' => 45, 'updated' => true]
            ),
            ($first->id + 100) => new AuditPage(
                $first->id + 100,
                'https://shop.acme.test/shoes',
                1,
                'done',
                200,
                'text/html',
                wordCount: 300,
                schemaTypes: ['BreadcrumbList'],
                hasAuthor: false,
                sourceLinks: 0,
                citation: ['questions' => 0, 'tables' => 0, 'lists' => 2, 'figures' => 0, 'lead_words' => 140, 'updated' => false]
            ),
            ($first->id + 200) => new AuditPage(
                $first->id + 200,
                'https://shop.acme.test/blog/lacing',
                1,
                'done',
                200,
                'text/html',
                wordCount: 700,
                schemaTypes: [],
                hasAuthor: false,
                sourceLinks: 0,
                citation: ['questions' => 0, 'tables' => 0, 'lists' => 0, 'figures' => 0, 'lead_words' => null, 'updated' => false]
            ),
            // Crawled before 0.43.0: only structured data is known.
            ($first->id + 300) => new AuditPage($first->id + 300, 'https://shop.acme.test/seeds/legacy', 1, 'done', 200, 'text/html', wordCount: 400, schemaTypes: ['Product']),
        ];
        $audits->finish($audit->id, 'completed', 90, ['error' => 0, 'warning' => 0, 'notice' => 0], [], null);

        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('Citation readiness of your top pages', $page);
        self::assertStringContainsString('latest site audit (2026-10-25)', $page);
        self::assertMatchesRegularExpression('#/guide/</td>\s*<td class="num">40</td>\s*<td class="num">8 / 8</td>\s*<td class="small">\s*<span class="muted">Nothing missing</span>#', $page, 'the trailing slash differs from the crawled URL');
        // Finding 36: a product page is not asked to name an author or cite sources; an article by address is.
        self::assertMatchesRegularExpression('#/shoes</td>\s*<td class="num">12</td>\s*<td class="num">1 / 6</td>#', $page);
        self::assertSame(1, preg_match('#/shoes</td>.*?</tr>#s', $page, $shoes));
        self::assertStringNotContainsString('Names an author', $shoes[0]);
        self::assertStringNotContainsString('Links to sources', $shoes[0]);
        self::assertStringContainsString('>Opens with a short answer</span>', $shoes[0]);
        self::assertSame(1, preg_match('#/blog/lacing</td>.*?</tr>#s', $page, $blog));
        self::assertStringContainsString('<td class="num">0 / 8</td>', $blog[0]);
        self::assertStringContainsString('>Names an author</span>, <span title="Mark up', $blog[0]);
        self::assertStringContainsString('>Links to sources</span>', $blog[0]);
        self::assertStringContainsString('Articles only.', $page);
        // Finding 38: one known check is not "ready"; the unknown applicable checks are named.
        self::assertSame(1, preg_match('#/seeds/legacy</td>.*?</tr>#s', $page, $legacy));
        self::assertStringContainsString('<td class="num">1 / 1</td>', $legacy[0]);
        self::assertStringContainsString('Nothing missing so far', $legacy[0]);
        self::assertStringContainsString('· 5 checks after the next site audit', $legacy[0]);
        self::assertStringNotContainsString('after the next site audit', $shoes[0], 'all applicable checks are known');
        self::assertSame(1, preg_match('#/guide/</td>.*?</tr>#s', $page, $guide));
        self::assertStringNotContainsString('so far', $guide[0], 'the article with every check known');
        self::assertStringContainsString('Not in the latest site audit', $page);
        self::assertStringContainsString('Tested repeatedly', $page);
        self::assertStringContainsString('Some tests, effect varies', $page);
    }

    public function testAiReferralsAreImportedWithGa4AndComparedWithThePreviousWindow(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        self::assertStringContainsString('Link a GA4 property under', (string) $owner->get($this->url())->getBody());

        $owner->get('/integrations/ga4');
        $owner->post('/integrations/ga4', ['credentials' => Ga4Fixture::json()]);
        $base = '/projects/' . $this->s->acmeProject->publicId;
        $owner->get($base . '/analytics');
        self::assertSame(303, $owner->post($base . '/analytics', ['property_id' => '123'])->getStatusCode());
        self::assertStringContainsString('The visits appear after the next GA4 import', (string) $owner->get($this->url())->getBody());

        $provider = $this->s->container->get(FakeGa4Provider::class);
        self::assertInstanceOf(FakeGa4Provider::class, $provider);
        $provider->aiReferralsError = new IntegrationException('GA4 quota exhausted.', true);
        $ga4 = $this->s->container->get(Ga4Service::class);
        self::assertInstanceOf(Ga4Service::class, $ga4);
        $ga4->run($this->s->acmeProject->id, $this->s->acme->id, $this->s->acmeProject->publicId);
        self::assertCount(1, $provider->calls, 'the landing-page report is still imported');
        self::assertStringContainsString('The last AI referral import failed: GA4 quota exhausted.', (string) $owner->get($this->url())->getBody());

        $provider->aiReferralsError = null;
        $provider->aiReferralsPerDay = ['chatgpt.com' => ['sessions' => 2, 'key_events' => 0.5, 'revenue' => 10.0], 'perplexity' => ['sessions' => 1, 'key_events' => 0.0, 'revenue' => 0.0]];
        $ga4->run($this->s->acmeProject->id, $this->s->acme->id, $this->s->acmeProject->publicId);
        $range = end($provider->aiReferralCalls);
        self::assertNotFalse($range);
        self::assertSame(['2026-08-30', '2026-10-24'], [$range->fromString(), $range->toString()], 'both 28-day windows, ending yesterday');

        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('Visits from AI assistants', $page);
        self::assertStringContainsString('2026-09-27 to 2026-10-24 from GA4, compared with the 28 days before', $page);
        self::assertStringContainsString('<p class="kpi-value">84</p>', $page, '3 sessions a day for 28 days');
        self::assertStringContainsString('280.00 EUR', $page);
        self::assertLessThan(strpos($page, '<td>Perplexity'), strpos($page, '<td>ChatGPT'), 'most sessions first');
        self::assertStringContainsString('no change', $page);

        $rival = $this->s->loggedIn($this->s->rivalOwner);
        self::assertSame(404, $rival->get($this->url())->getStatusCode());
    }

    private function topPage(string $url, int $clicks): void
    {
        $performance = $this->s->container->get(InMemorySearchPerformance::class);
        self::assertInstanceOf(InMemorySearchPerformance::class, $performance);
        $pages = $performance->details[$this->s->acmeProject->id]['2026-10-20']['page'] ?? [];
        $pages[] = new DimensionRow($url, new Metrics($clicks, $clicks * 20, 4.0));
        $performance->replaceDay($this->s->acmeProject->id, '2026-10-20', ['query' => [], 'page' => $pages]);
    }

    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/ai-visibility';
    }
}
