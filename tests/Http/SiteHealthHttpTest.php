<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Modules\Alerts\AlertDetector;
use SeoControlCenter\Modules\Alerts\AlertType;
use SeoControlCenter\Modules\SiteHealth\SiteHealthService;
use SeoControlCenter\Tests\Support\Fakes\InMemorySiteHealth;
use SeoControlCenter\Tests\Support\Scenario;

/** Site health monitoring (1.3.0): checks, events, alerts, page and permissions. */
final class SiteHealthHttpTest extends TestCase
{
    private Scenario $s;
    private FrozenClock $clock;
    private InMemorySiteHealth $store;
    private SiteHealthService $health;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-11-16 10:00:00'));
        $this->s = new Scenario(clock: $this->clock);
        $this->store = $this->s->container->get(InMemorySiteHealth::class);
        $this->health = $this->s->container->get(SiteHealthService::class);
    }

    public function testDownAfterTwoFailuresThenRecovered(): void
    {
        $id = $this->s->acmeProject->id;
        $home = 'https://shop.acme.test/';
        $this->store->answers[$home] = ['status' => 200, 'body' => 'ok', 'ms' => 120, 'error' => null];
        $this->runCheck('uptime');
        self::assertSame('up', $this->store->state($id)->uptimeStatus);

        $this->store->answers[$home] = ['status' => 503, 'body' => '', 'ms' => 30, 'error' => null];
        $this->runCheck('uptime');
        self::assertSame([], $this->store->events($id, null, 10), 'One failure may be a hiccup.');
        self::assertSame('up', $this->store->state($id)->uptimeStatus);
        $this->runCheck('uptime');
        $events = $this->store->events($id, null, 10);
        self::assertSame(['down'], array_column($events, 'kind'));
        self::assertStringContainsString('HTTP 503', $events[0]['detail']);
        self::assertSame('2026-11-16 10:30:00', $this->store->state($id)->downSince);
        $this->runCheck('uptime');
        self::assertCount(1, $this->store->events($id, null, 10), 'Still down is not a new event.');

        $this->store->answers[$home] = ['status' => 301, 'body' => '', 'ms' => 50, 'error' => null];
        $this->runCheck('uptime');
        self::assertSame(['recovered', 'down'], array_column($this->store->events($id, null, 10), 'kind'));
        self::assertNull($this->store->state($id)->downSince);

        $alerts = array_values(array_filter($this->s->container->get(AlertDetector::class)->project($id, $this->s->acmeProject->publicId, 'Acme Shop'), static fn(array $e): bool => $e['type'] === AlertType::SiteDown));
        self::assertCount(2, $alerts);
        self::assertStringStartsWith('Acme Shop: The site', $alerts[0]['title']);
        self::assertStringContainsString('/site-health#event-', $alerts[0]['url']);
    }

    public function testDailyChecksWarnOnceAndReportChanges(): void
    {
        $id = $this->s->acmeProject->id;
        $this->store->certificate = ['expires' => '2026-11-26 00:00:00', 'issuer' => "Let's Encrypt", 'error' => null];
        $this->store->domain = ['expires' => '2027-06-01 00:00:00', 'registrar' => 'Example Registrar', 'error' => null];
        $this->store->answers['https://shop.acme.test/robots.txt'] = ['status' => 200, 'body' => "User-agent: *\nDisallow: /cart\n", 'ms' => 10, 'error' => null];
        $this->store->sitemapCount = 400;
        $this->runCheck('daily');
        $state = $this->store->state($id);
        self::assertSame('acme.test', $state->domainName);
        self::assertSame(400, $state->sitemapUrls);
        self::assertSame(['ssl'], array_column($this->store->events($id, null, 10), 'kind'), 'The certificate expires within 14 days; robots and sitemap set a baseline.');
        self::assertSame('The TLS certificate expires in 9 days', $this->store->events($id, null, 10)[0]['title']);

        $this->clock->advance('+1 day');
        $this->store->answers['https://shop.acme.test/robots.txt']['body'] = "User-agent: *\nDisallow: /\n";
        $this->store->sitemapCount = 300;
        $this->health->check($id, $this->s->acmeProject->workspaceId, 'shop.acme.test', 'daily');
        $kinds = array_column($this->store->events($id, null, 10), 'kind', 'title');
        self::assertSame('robots', $kinds['robots.txt now blocks the whole site']);
        self::assertSame('sitemap', $kinds['The sitemap lost 100 pages']);
        self::assertCount(3, $kinds, 'The certificate warning for 14 days is not repeated.');
        self::assertSame(1, $this->store->domainLookups, 'The domain is looked up once a week.');
        $robots = array_values(array_filter($this->store->events($id, null, 10), static fn(array $e): bool => $e['kind'] === 'robots'))[0];
        self::assertStringContainsString("+ Disallow: /\n− Disallow: /cart", $robots['detail']);
    }

    public function testPageSettingsAndDispatch(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $base = '/projects/' . $this->s->acmeProject->publicId . '/site-health';
        $page = (string) $owner->get($base)->getBody();
        self::assertStringContainsString('Not checked yet; the first check runs within 15 minutes.', $page);
        self::assertStringContainsString('No events yet', $page);

        $owner->post($base . '/check');
        self::assertSame(['uptime', 'daily'], array_column($this->store->scheduled, 'kind'));
        $owner->get($base);
        $owner->post($base . '/settings', ['enabled' => '0']);
        self::assertFalse($this->store->state($this->s->acmeProject->id)->enabled);
        self::assertStringContainsString('monitoring is <strong>off</strong>', (string) $owner->get($base)->getBody());
        self::assertContains('project.site_health_disabled', $this->s->audit()->actions());

        $this->store->projects = [['projectId' => $this->s->acmeProject->id, 'workspaceId' => $this->s->acmeProject->workspaceId, 'domain' => 'shop.acme.test'], ['projectId' => $this->s->acmeOther->id, 'workspaceId' => $this->s->acmeOther->workspaceId, 'domain' => 'blog.acme.test']];
        $this->store->scheduled = [];
        $this->health->dispatch();
        self::assertSame([['blog.acme.test', 'uptime'], ['blog.acme.test', 'daily']], array_map(static fn(array $c): array => [$c['domain'], $c['kind']], $this->store->scheduled), 'Turned-off projects are not checked.');

        $viewer = $this->s->loggedIn($this->s->viewer);
        $viewerPage = (string) $viewer->get($base)->getBody();
        self::assertStringNotContainsString('Turn monitoring on', $viewerPage);
        $viewer->get('/account');
        $csrf = $viewer->csrfToken();
        self::assertSame(403, $viewer->post($base . '/settings', ['_csrf' => $csrf, 'enabled' => '1'])->getStatusCode());
        self::assertSame(403, $viewer->post($base . '/check', ['_csrf' => $csrf])->getStatusCode());
    }

    /** @param 'uptime'|'daily' $kind */
    private function runCheck(string $kind): void
    {
        $this->health->check($this->s->acmeProject->id, $this->s->acmeProject->workspaceId, 'shop.acme.test', $kind);
        $this->clock->advance('+15 minutes');
    }
}
