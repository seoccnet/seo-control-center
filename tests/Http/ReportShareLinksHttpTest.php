<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Tests\Support\Fakes\InMemoryShareLinks;
use SeoControlCenter\Tests\Support\Scenario;

/** Read-only client links to reports (1.3.0). */
final class ReportShareLinksHttpTest extends TestCase
{
    public function testCreateOpenAndRevoke(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-11-16 10:00:00'));
        $s = new Scenario(clock: $clock);
        $store = $s->container->get(InMemoryShareLinks::class);
        $owner = $s->loggedIn($s->owner);
        $reports = '/projects/' . $s->acmeProject->publicId . '/reports';
        $owner->get($reports);
        $owner->post($reports . '/links', ['label' => 'Garden Shop Ltd', 'days' => '30']);
        $page = (string) $owner->get($reports)->getBody();
        self::assertMatchesRegularExpression('#value="https?://[^"]+/share/([A-Za-z0-9_-]{43})"#', $page);
        preg_match('#/share/([A-Za-z0-9_-]{43})"#', $page, $m);
        $token = $m[1] ?? self::fail('No client link on the page.');
        self::assertStringNotContainsString($token, (string) $owner->get($reports)->getBody(), 'The full link is shown only once.');
        $link = array_values($store->links)[0];
        self::assertSame(['Garden Shop Ltd', '2026-12-16 10:00:00', substr($token, 0, 6)], [$link->label, $link->expiresAt, $link->tokenHint]);
        self::assertNotContains($token, $store->hashes, 'Only the hash of the token is stored.');
        self::assertContains('project.report_link_created', $s->audit()->actions());

        // A visitor without an account sees the report and nothing of the app.
        $visitor = $s->browser();
        $response = $visitor->get('/share/' . $token);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        $html = (string) $response->getBody();
        self::assertStringContainsString('Acme Shop', $html);
        self::assertStringContainsString('href="/share/' . $token . '/branding.css"', $html);
        self::assertStringContainsString('/share/' . $token . '/pdf?frequency=weekly', $html);
        self::assertStringNotContainsString('/projects/' . $s->acmeProject->publicId, $html, 'No links into the app.');
        self::assertStringNotContainsString('class="sidebar"', $html);
        self::assertSame('text/css; charset=utf-8', $visitor->get('/share/' . $token . '/branding.css')->getHeaderLine('Content-Type'));
        self::assertSame('application/pdf', $visitor->get('/share/' . $token . '/pdf')->getHeaderLine('Content-Type'));
        self::assertSame(1, $store->links[$link->id]->views);

        $owner->get($reports);
        $owner->post($reports . '/links/' . $link->publicId . '/revoke');
        self::assertSame(404, $s->browser()->get('/share/' . $token)->getStatusCode());
        self::assertContains('project.report_link_revoked', $s->audit()->actions());
    }

    public function testExpiredUnknownAndDeletedLinksAreNotFound(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-11-16 10:00:00'));
        $s = new Scenario(clock: $clock);
        $owner = $s->loggedIn($s->owner);
        $reports = '/projects/' . $s->acmeProject->publicId . '/reports';
        $owner->get($reports);
        $owner->post($reports . '/links', ['label' => 'Client', 'days' => '7']);
        preg_match('#/share/([A-Za-z0-9_-]{43})"#', (string) $owner->get($reports)->getBody(), $m);
        $token = $m[1] ?? self::fail('No client link on the page.');
        self::assertSame(200, $s->browser()->get('/share/' . $token)->getStatusCode());

        $clock->advance('+8 days');
        self::assertSame(404, $s->browser()->get('/share/' . $token)->getStatusCode(), 'Expired.');
        self::assertSame(404, $s->browser()->get('/share/' . str_repeat('A', 43))->getStatusCode(), 'Unknown.');
        self::assertSame(404, $s->browser()->get('/share/short')->getStatusCode(), 'Malformed.');
        self::assertStringContainsString('Expired', (string) $owner->get($reports)->getBody());

        $clock->advance('-8 days');
        $s->dir->deleted[$s->acmeProject->id] = true;
        self::assertSame(404, $s->browser()->get('/share/' . $token)->getStatusCode(), 'The project was deleted.');
    }
}
