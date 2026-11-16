<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Fakes\InMemorySiteHealth;
use SeoControlCenter\Tests\Support\Scenario;

final class SiteHealthIsolationTest extends TestCase
{
    public function testForeignProjectsAreNotFound(): void
    {
        $s = new Scenario();
        $store = $s->container->get(InMemorySiteHealth::class);
        $store->addEvent($s->rivalProject->id, 'down', 'down:x', 'Rival secret outage', 'detail', '2026-11-16 10:00:00');
        $member = $s->loggedIn($s->member);
        $member->get('/account');
        $csrf = $member->csrfToken();
        foreach ([$s->rivalProject, $s->acmeOther] as $project) {
            $base = '/projects/' . $project->publicId . '/site-health';
            self::assertSame(404, $member->get($base)->getStatusCode());
            self::assertSame(404, $member->post($base . '/settings', ['_csrf' => $csrf, 'enabled' => '0'])->getStatusCode());
            self::assertSame(404, $member->post($base . '/check', ['_csrf' => $csrf])->getStatusCode());
        }
        self::assertStringNotContainsString('Rival secret outage', (string) $s->loggedIn($s->owner)->get('/projects/' . $s->acmeProject->publicId . '/site-health')->getBody());
        self::assertSame([], $store->scheduled);
        self::assertTrue($store->state($s->rivalProject->id)->enabled);
    }
}
