<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Local\LocalArea;
use SeoControlCenter\Modules\Local\LocalLocation;
use SeoControlCenter\Tests\Support\Fakes\InMemoryLocations;
use SeoControlCenter\Tests\Support\Scenario;

final class LocationsIsolationTest extends TestCase
{
    public function testEveryLocationRouteChecksVisibilityPermissionAndCsrf(): void
    {
        $s = new Scenario();
        $repo = $s->container->make(InMemoryLocations::class);
        $uuid = '00000000-0000-4000-8000-000000000001';
        $repo->save(new LocalLocation($uuid, $s->acmeProject->id, 'East', '222', new LocalArea(1, 1), 'en', ['shop'], false));
        $viewer = $s->loggedIn($s->viewer);
        $base = '/projects/' . $s->acmeProject->publicId . '/local/locations';
        self::assertSame(200, $viewer->get($base)->getStatusCode());
        foreach (['', '/' . $uuid . '/settings', '/' . $uuid . '/delete', '/' . $uuid . '/check', '/' . $uuid . '/reviews', '/' . $uuid . '/grid'] as $suffix) {
            $viewer->get('/');
            self::assertSame(403, $viewer->post($base . $suffix)->getStatusCode(), $suffix);
        }
        $member = $s->loggedIn($s->member);
        foreach ([$s->acmeOther, $s->rivalProject] as $project) {
            $foreign = '/projects/' . $project->publicId . '/local/locations';
            self::assertSame(404, $member->get($foreign)->getStatusCode());
            foreach (['', '/' . $uuid . '/settings', '/' . $uuid . '/delete', '/' . $uuid . '/check', '/' . $uuid . '/reviews', '/' . $uuid . '/grid'] as $suffix) {
                $member->get('/');
                self::assertSame(404, $member->post($foreign . $suffix)->getStatusCode());
            }
        }
        $owner = $s->loggedIn($s->owner);
        foreach (['', '/' . $uuid . '/settings', '/' . $uuid . '/delete', '/' . $uuid . '/check', '/' . $uuid . '/reviews', '/' . $uuid . '/grid'] as $suffix) {
            $owner->get('/');
            self::assertSame(419, $owner->post($base . $suffix, withCsrf: false)->getStatusCode());
        }
    }
}
