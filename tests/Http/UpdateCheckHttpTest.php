<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Domain\Accounts\SystemRole;
use SeoControlCenter\Modules\System\UpdateCheck;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Scenario;

/** Optional update check against GitHub Releases (ADR-053). */
final class UpdateCheckHttpTest extends TestCase
{
    public function testOffByDefaultThenOptInShowsNewerReleases(): void
    {
        $s = new Scenario();
        $http = $s->container->get(FakeHttpClient::class);
        $http->json('GET', 'https://api.github.com/repos/seoccnet/seo-control-center/releases/latest', ['tag_name' => 'v99.0.0', 'name' => '99.0.0 — Next', 'html_url' => 'https://github.com/seoccnet/seo-control-center/releases/tag/v99.0.0', 'published_at' => '2026-12-01T10:00:00Z']);
        $check = $s->container->get(UpdateCheck::class);

        // Off: the job contacts nobody.
        $check->check();
        self::assertSame([], $http->requests);
        $root = $s->loggedIn($s->addUser('root@acme.test', SystemRole::Admin));
        $page = (string) $root->get('/system/updates')->getBody();
        self::assertStringContainsString('Turn the update check on', $page);
        self::assertStringNotContainsString('is available', $page);

        $root->post('/system/updates/check', ['action' => 'enable']);
        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame('SEO-Control-Center-update-check', $request['headers']['User-Agent']);
        self::assertNull($request['body']);
        self::assertStringNotContainsString('?', $request['url'], 'No installation data in the request.');
        self::assertContains('system.update_check_enabled', $s->audit()->actions());

        $page = (string) $root->get('/system/updates')->getBody();
        self::assertStringContainsString('SEOcc 99.0.0 is available.', $page);
        self::assertStringContainsString('href="/system/updates#update-check">SEOcc 99.0.0 is available</a>', $page, 'Top-bar notice for administrators.');
        self::assertStringNotContainsString('SEOcc 99.0.0 is available', (string) $s->loggedIn($s->owner)->get('/projects')->getBody(), 'Workspace owners are not system administrators.');

        $root->get('/system/updates');
        $root->post('/system/updates/check', ['action' => 'disable']);
        self::assertFalse($check->status()['enabled']);
        $check->check();
        self::assertCount(1, $http->requests, 'Turned off: no more requests.');
        self::assertStringNotContainsString('is available</a>', (string) $root->get('/system/updates')->getBody());
    }

    public function testSameVersionErrorsAndPermissions(): void
    {
        $s = new Scenario();
        $http = $s->container->get(FakeHttpClient::class);
        $version = (string) $s->container->get('app.version');
        $http->json('GET', 'https://api.github.com/repos/', ['tag_name' => $version, 'html_url' => 'https://evil.test/']);
        $root = $s->loggedIn($s->addUser('root@acme.test', SystemRole::Admin));
        $root->get('/system/updates');
        $root->post('/system/updates/check', ['action' => 'enable']);
        $status = $s->container->get(UpdateCheck::class)->status();
        self::assertFalse($status['newer']);
        self::assertSame('https://github.com/seoccnet/seo-control-center/releases', $status['latest']['url'] ?? null, 'Only GitHub links are kept.');
        self::assertStringContainsString('You have the latest release', (string) $root->get('/system/updates')->getBody());

        $http->on('GET', 'https://api.github.com/repos/', static fn(): HttpResponse => new HttpResponse(500, ''));
        $root->get('/system/updates');
        $root->post('/system/updates/check', ['action' => 'check']);
        self::assertSame('GitHub answered HTTP 500 without a release version.', $s->container->get(UpdateCheck::class)->status()['error']);

        $owner = $s->loggedIn($s->owner);
        $owner->get('/account');
        $csrf = $owner->csrfToken();
        self::assertSame(403, $owner->post('/system/updates/check', ['_csrf' => $csrf, 'action' => 'enable'])->getStatusCode());
    }
}
