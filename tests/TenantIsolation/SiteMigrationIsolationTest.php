<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Fakes\InMemorySiteMigrations;
use SeoControlCenter\Tests\Support\Scenario;

final class SiteMigrationIsolationTest extends TestCase
{
    public function testForeignPlansAndProjectsAreNotFound(): void
    {
        $s = new Scenario();
        $store = $s->container->get(InMemorySiteMigrations::class);
        $rivalPlan = $store->create($s->rivalProject->id, $s->rivalProject->workspaceId, 'Rival move', 'https://rival.test', 'https://new.rival.test', null, $s->rivalOwner->id);
        $otherPlan = $store->create($s->acmeOther->id, $s->acmeOther->workspaceId, 'Blog move', 'https://blog.acme.test', 'https://new.acme.test', null, $s->owner->id);
        $store->addUrls($rivalPlan->id, [['url' => 'https://rival.test/a', 'clicks' => 5]], 10);
        $key = array_values($store->urls[$rivalPlan->id])[0]->key;

        $member = $s->loggedIn($s->member);
        $member->get('/account');
        $csrf = $member->csrfToken();
        foreach ([[$s->rivalProject, $rivalPlan], [$s->acmeOther, $otherPlan]] as [$project, $plan]) {
            $base = '/projects/' . $project->publicId . '/site-migration';
            self::assertSame(404, $member->get($base)->getStatusCode());
            self::assertSame(404, $member->get($base . '/' . $plan->publicId)->getStatusCode());
            self::assertSame(404, $member->get($base . '/' . $plan->publicId . '/export?format=csv')->getStatusCode());
            foreach (['', '/old', '/new', '/suggest', '/test', '/target', '/delete'] as $action) {
                self::assertSame(404, $member->post($base . '/' . $plan->publicId . $action, ['_csrf' => $csrf, 'key' => $key, 'target' => '', 'urls' => '/x', 'name' => 'x'])->getStatusCode(), $action);
            }
        }
        // A foreign plan ID is not reachable through a project the member can see.
        $own = '/projects/' . $s->acmeProject->publicId . '/site-migration/' . $rivalPlan->publicId;
        self::assertSame(404, $member->get($own)->getStatusCode());
        $owner = $s->loggedIn($s->owner);
        $owner->get('/account');
        $ownerCsrf = $owner->csrfToken();
        self::assertSame(404, $owner->post($own . '/target', ['_csrf' => $ownerCsrf, 'key' => $key, 'target' => ''])->getStatusCode());
        self::assertSame(404, $owner->post($own . '/delete', ['_csrf' => $ownerCsrf])->getStatusCode());

        self::assertCount(2, $store->plans);
        self::assertNull(array_values($store->urls[$rivalPlan->id])[0]->targetUrl);
        self::assertSame([], $store->scheduled);
        self::assertSame(419, $s->loggedIn($s->owner)->post('/projects/' . $s->acmeProject->publicId . '/site-migration', ['name' => 'x'], withCsrf: false)->getStatusCode());
    }
}
