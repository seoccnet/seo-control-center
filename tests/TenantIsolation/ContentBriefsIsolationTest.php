<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\ContentBriefs\BriefRepository;
use SeoControlCenter\Tests\Support\Scenario;

final class ContentBriefsIsolationTest extends TestCase
{
    public function testEveryRouteEnforcesProjectAccessEditorRightsAndCsrf(): void
    {
        $s = new Scenario();
        $base = '/projects/' . $s->acmeProject->publicId . '/content-briefs';
        $input = ['title' => 'Shoes', 'keyword' => 'shoes', 'status' => 'draft'];
        $owner = $s->loggedIn($s->owner);
        $owner->get($base . '/new');
        self::assertSame(419, $owner->post($base, $input, withCsrf: false)->getStatusCode());
        $owner->get($base . '/new');
        self::assertSame(303, $owner->post($base, $input)->getStatusCode());
        $repo = $s->container->make(BriefRepository::class);
        $brief = $repo->list($s->acmeProject, '', '', 25, 0)[0];
        $self = $base . '/' . $brief->publicId;
        $viewer = $s->loggedIn($s->viewer);
        foreach ([$base, $self, $self . '/print', $self . '/export'] as $route) {
            self::assertSame(200, $viewer->get($route)->getStatusCode());
        }
        foreach ([$base . '/new', $self . '/edit'] as $route) {
            self::assertSame(403, $viewer->get($route)->getStatusCode());
        }
        foreach ([$base, $self, $self . '/delete'] as $route) {
            $viewer->get('/account');
            self::assertSame(403, $viewer->post($route, ['revision' => '1'] + $input)->getStatusCode());
        }
        foreach ([$s->unassigned, $s->rivalOwner] as $user) {
            $other = $s->loggedIn($user);
            foreach ([$base, $base . '/new', $self, $self . '/edit', $self . '/print', $self . '/export'] as $route) {
                self::assertSame(404, $other->get($route)->getStatusCode());
            }
            foreach ([$base, $self, $self . '/delete'] as $route) {
                $other->get('/account');
                self::assertSame(404, $other->post($route, ['revision' => '1'] + $input)->getStatusCode());
            }
        }
        $foreign = '/projects/' . $s->acmeOther->publicId . '/content-briefs/' . $brief->publicId;
        foreach (['', '/edit', '/print', '/export'] as $suffix) {
            self::assertSame(404, $owner->get($foreign . $suffix)->getStatusCode());
        }
        foreach ([$foreign, $foreign . '/delete'] as $route) {
            $owner->get('/account');
            self::assertSame(404, $owner->post($route, ['revision' => '1'] + $input)->getStatusCode());
        }
        $owner->get($self . '/edit');
        self::assertSame(419, $owner->post($self, $input, withCsrf: false)->getStatusCode());
        $owner->get($self);
        self::assertSame(409, $owner->post($self . '/delete', ['revision' => '2'])->getStatusCode());
        $owner->get($self);
        self::assertSame(303, $owner->post($self . '/delete', ['revision' => '1'])->getStatusCode());
        self::assertSame(404, $owner->get($self)->getStatusCode());
        self::assertContains('project.brief_removed', $s->audit()->actions());
    }
}
