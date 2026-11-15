<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Fakes\InMemoryBotLogs;
use SeoControlCenter\Tests\Support\Scenario;

final class BotLogsIsolationTest extends TestCase
{
    public function testForeignLogsAndProjectsAreNotFound(): void
    {
        $s = new Scenario();
        $store = $s->container->get(InMemoryBotLogs::class);
        $rivalLog = $store->create($s->rivalProject->id, $s->rivalProject->workspaceId, 'rival.log', 10, $s->rivalOwner->id);
        $store->finish($rivalLog->id, 'done');
        $store->hits[$rivalLog->id]['googlebot|/secret-rival-path|200'] = ['bot' => 'googlebot', 'path' => '/secret-rival-path', 'status' => 200, 'hits' => 3];

        $member = $s->loggedIn($s->member);
        $member->get('/account');
        $csrf = $member->csrfToken();
        foreach ([$s->rivalProject, $s->acmeOther] as $project) {
            $base = '/projects/' . $project->publicId . '/bot-logs';
            self::assertSame(404, $member->get($base)->getStatusCode());
            self::assertSame(404, $member->post($base, ['_csrf' => $csrf])->getStatusCode());
            self::assertSame(404, $member->post($base . '/' . $rivalLog->publicId . '/delete', ['_csrf' => $csrf])->getStatusCode());
        }
        // A foreign log ID is not reachable through a project the member can see.
        $own = '/projects/' . $s->acmeProject->publicId . '/bot-logs';
        self::assertSame(404, $member->get($own . '?log=' . $rivalLog->publicId)->getStatusCode());
        $owner = $s->loggedIn($s->owner);
        $owner->get('/account');
        self::assertSame(404, $owner->post($own . '/' . $rivalLog->publicId . '/delete', ['_csrf' => $owner->csrfToken()])->getStatusCode());
        self::assertStringNotContainsString('secret-rival-path', (string) $owner->get($own)->getBody());
        self::assertCount(1, $store->imports);
        self::assertSame(419, $owner->post($own, [], withCsrf: false)->getStatusCode());
    }
}
