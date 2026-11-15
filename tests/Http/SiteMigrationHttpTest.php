<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\SiteMigration\RedirectCheck;
use SeoControlCenter\Modules\SiteMigration\SiteMigrationService;
use SeoControlCenter\Tests\Support\Fakes\InMemorySiteMigrations;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Site migration assistant (1.2.0): plans, address imports, suggestions,
 * manual targets, redirect tests, exports and permissions.
 */
final class SiteMigrationHttpTest extends TestCase
{
    public function testPlanFromAddressesToTestedRedirects(): void
    {
        $s = new Scenario();
        $store = $s->container->get(InMemorySiteMigrations::class);
        $service = $s->container->get(SiteMigrationService::class);
        $owner = $s->loggedIn($s->owner);
        $owner->get('/account');
        $csrf = ['_csrf' => $owner->csrfToken()];
        $base = '/projects/' . $s->acmeProject->publicId . '/site-migration';

        self::assertStringContainsString('No migration plans yet', (string) $owner->get($base)->getBody());
        $created = $owner->post($base, $csrf + ['name' => 'New shop', 'old_origin' => 'https://shop.acme.test', 'new_origin' => 'new.acme.test', 'launch_date' => '']);
        self::assertSame(303, $created->getStatusCode());
        $url = $created->getHeaderLine('Location');
        self::assertMatchesRegularExpression('#^' . preg_quote($base, '#') . '/[0-9a-f-]{36}$#', $url);
        $plan = array_values($store->plans)[0];
        self::assertSame('https://new.acme.test', $plan->newOrigin);

        $owner->post($url . '/old', $csrf + ['source' => 'paste', 'urls' => "/products/rain-barrel\nhttps://shop.acme.test/about.html\nhttps://elsewhere.test/x\n/gone"]);
        $owner->post($url . '/new', $csrf + ['source' => 'paste', 'urls' => "/shop/rain-barrel\n/about\n"]);
        self::assertCount(3, $store->urls[$plan->id]);
        self::assertSame(['https://new.acme.test/shop/rain-barrel', 'https://new.acme.test/about'], $store->targets[$plan->id]);

        $owner->post($url . '/suggest', $csrf);
        self::assertSame('match', $store->scheduled[0]['task']);
        $service->runMatch($plan->id);
        $page = (string) $owner->get($url)->getBody();
        self::assertStringContainsString('https://new.acme.test/shop/rain-barrel', $page);
        self::assertStringContainsString('Slug · 85%', $page);

        // A manual target must stay on the new site.
        $gone = array_values(array_filter($store->urls[$plan->id], static fn($r): bool => str_ends_with($r->oldUrl, '/gone')))[0];
        self::assertSame(303, $owner->post($url . '/target', $csrf + ['key' => $gone->key, 'target' => 'https://evil.test/'])->getStatusCode());
        self::assertNull($store->urls[$plan->id][$gone->id]->targetUrl);
        $owner->post($url . '/target', $csrf + ['key' => $gone->key, 'target' => '/about']);
        self::assertSame('https://new.acme.test/about', $store->urls[$plan->id][$gone->id]->targetUrl);
        self::assertTrue($store->urls[$plan->id][$gone->id]->manual);
        self::assertSame(404, $owner->post($url . '/target', $csrf + ['key' => str_repeat('a', 40), 'target' => ''])->getStatusCode());

        $store->answers = [
            'https://shop.acme.test/products/rain-barrel' => ['status' => 301, 'location' => 'https://new.acme.test/shop/rain-barrel', 'error' => null],
            'https://new.acme.test/shop/rain-barrel' => ['status' => 200, 'location' => null, 'error' => null],
            'https://shop.acme.test/about.html' => ['status' => 302, 'location' => 'https://new.acme.test/about', 'error' => null],
            'https://new.acme.test/about' => ['status' => 200, 'location' => null, 'error' => null],
        ];
        $owner->post($url . '/test', $csrf);
        self::assertSame('test', $store->scheduled[1]['task']);
        $service->runTest($plan->id, static fn(): int => 600);
        $states = [];
        foreach ($store->urls[$plan->id] as $row) {
            $states[(string) parse_url($row->oldUrl, PHP_URL_PATH)] = $row->testState;
        }
        self::assertSame(['/products/rain-barrel' => RedirectCheck::OK, '/about.html' => RedirectCheck::TEMPORARY, '/gone' => RedirectCheck::MISSING], $states);
        self::assertSame('done', $store->plans[$plan->id]->testState);
        self::assertStringContainsString('To fix (2)', (string) $owner->get($url)->getBody());

        $apache = $owner->get($url . '/export?format=apache');
        self::assertStringContainsString('attachment; filename="new-shop-redirects.htaccess"', $apache->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('Redirect 301 /products/rain-barrel https://new.acme.test/shop/rain-barrel', (string) $apache->getBody());
        self::assertStringContainsString('location = /gone { return 301 https://new.acme.test/about; }', (string) $owner->get($url . '/export?format=nginx')->getBody());
        self::assertStringContainsString('old_url,target_url', (string) $owner->get($url . '/export?format=csv')->getBody());

        $owner->post($url . '/delete', $csrf);
        self::assertSame([], $store->plans);
    }

    public function testTestsResumeFromTheCursorWhenTimeRunsShort(): void
    {
        $s = new Scenario();
        $store = $s->container->get(InMemorySiteMigrations::class);
        $service = $s->container->get(SiteMigrationService::class);
        $plan = $store->create($s->acmeProject->id, $s->acmeProject->workspaceId, 'Big move', 'https://shop.acme.test', 'https://shop.acme.test', null, $s->owner->id);
        $store->addUrls($plan->id, array_map(static fn(int $i): array => ['url' => 'https://shop.acme.test/p' . $i, 'clicks' => null], range(1, 30)), 100);
        $store->setState($plan->id, 'test', 'queued');

        $service->runTest($plan->id, static fn(): int => 5);
        self::assertSame('running', $store->plans[$plan->id]->testState);
        self::assertSame(25, $store->plans[$plan->id]->testCursor);
        self::assertSame(['task' => 'test', 'id' => $plan->id, 'cursor' => 25], $store->scheduled[0]);
        self::assertCount(25, $store->probed);

        $service->runTest($plan->id, static fn(): int => 600);
        self::assertSame('done', $store->plans[$plan->id]->testState);
        self::assertCount(30, $store->probed, 'No address is requested twice.');
    }

    public function testValidationAndPermissions(): void
    {
        $s = new Scenario();
        $store = $s->container->get(InMemorySiteMigrations::class);
        $base = '/projects/' . $s->acmeProject->publicId . '/site-migration';
        $owner = $s->loggedIn($s->owner);
        $owner->get('/account');
        $csrf = ['_csrf' => $owner->csrfToken()];

        // Neither site is on the project's domain; http:// and paths are refused.
        $owner->post($base, $csrf + ['name' => 'Elsewhere', 'old_origin' => 'https://a.test', 'new_origin' => 'https://b.test']);
        $owner->post($base, $csrf + ['name' => 'Insecure', 'old_origin' => 'http://shop.acme.test', 'new_origin' => 'https://shop.acme.test']);
        $owner->post($base, $csrf + ['name' => 'Bad date', 'old_origin' => 'https://shop.acme.test', 'new_origin' => 'https://shop.acme.test', 'launch_date' => '2026-02-30']);
        self::assertSame([], $store->plans);

        $viewer = $s->loggedIn($s->viewer);
        $viewer->get('/account');
        $viewerCsrf = ['_csrf' => $viewer->csrfToken()];
        self::assertSame(200, $viewer->get($base)->getStatusCode());
        self::assertStringNotContainsString('New migration plan', (string) $viewer->get($base)->getBody());
        self::assertSame(403, $viewer->post($base, $viewerCsrf + ['name' => 'Move', 'old_origin' => 'https://shop.acme.test', 'new_origin' => 'https://new.acme.test'])->getStatusCode());
        $plan = $store->create($s->acmeProject->id, $s->acmeProject->workspaceId, 'Move', 'https://shop.acme.test', 'https://new.acme.test', null, $s->owner->id);
        self::assertSame(200, $viewer->get($base . '/' . $plan->publicId)->getStatusCode());
        self::assertSame(403, $viewer->post($base . '/' . $plan->publicId . '/test', $viewerCsrf)->getStatusCode());
        self::assertSame(403, $viewer->post($base . '/' . $plan->publicId . '/delete', $viewerCsrf)->getStatusCode());
        self::assertSame([], $store->scheduled);
    }
}
