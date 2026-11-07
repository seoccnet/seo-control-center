<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\AiVisibility\AiCrawlerCheck;
use SeoControlCenter\Modules\AiVisibility\AiCrawlerService;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryAiCrawlers;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * AI visibility readiness (0.43.0): the crawler check of a project is
 * requested, run and shown only inside its own workspace.
 */
final class AiReadinessIsolationTest extends TestCase
{
    public function testCrawlerChecksStayInsideTheirWorkspace(): void
    {
        $s = new Scenario();
        $crawlers = $s->container->make(InMemoryAiCrawlers::class);
        $crawlers->save($s->rival->id, new AiCrawlerCheck($s->rivalProject->id, 'https://rival.test/robots.txt', 'ok', 200, [['token' => 'OAI-SearchBot', 'state' => 'blocked', 'named' => true, 'blocked' => ['/rival-secret']]], 'found', 200, 12, '2026-10-25 08:00:00'));
        foreach ([$s->viewer, $s->unassigned, $s->rivalOwner] as $user) {
            $browser = $s->loggedIn($user);
            $browser->get('/account');
            self::assertSame($user === $s->viewer ? 403 : 404, $browser->post('/projects/' . $s->acmeProject->publicId . '/ai-visibility/crawlers/check')->getStatusCode());
        }
        self::assertSame([], $crawlers->queued);

        $owner = $s->loggedIn($s->owner);
        $owner->get('/account');
        self::assertSame(404, $owner->post('/projects/' . $s->rivalProject->publicId . '/ai-visibility/crawlers/check')->getStatusCode());
        $page = (string) $owner->get('/projects/' . $s->acmeProject->publicId . '/ai-visibility')->getBody();
        self::assertStringNotContainsString('/rival-secret', $page);
        self::assertStringNotContainsString('rival.test/robots.txt', $page);

        $s->container->make(AiCrawlerService::class)->run($s->rivalProject->id, $s->acme->id, $s->rivalProject->publicId);
        self::assertSame([], $s->container->make(FakeHttpClient::class)->requests, 'a payload naming another workspace fetches nothing');
        self::assertSame('https://rival.test/robots.txt', $crawlers->latest($s->rivalProject->id)?->robotsUrl);
    }
}
