<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\Annotations\AnnotationRepository;
use SeoControlCenter\Tests\Support\Scenario;

final class AnnotationsIsolationTest extends TestCase
{
    public function testCreateEditDeleteRequireScopedEditorAndCsrf(): void
    {
        $s = new Scenario();
        $path = '/projects/' . $s->acmeProject->publicId . '/annotations';
        $input = ['date' => $s->container->make(Clock::class)->now()->format('Y-m-d'), 'kind' => 'title', 'title' => 'Updated title', 'note' => 'Changed on the website', 'url' => 'https://shop.acme.test/Shoes?size=M'];
        $owner = $s->loggedIn($s->owner);
        self::assertSame(200, $owner->get($path)->getStatusCode());
        self::assertSame(419, $owner->post($path, $input, withCsrf: false)->getStatusCode());
        $owner->get($path);
        self::assertSame(303, $owner->post($path, $input)->getStatusCode());
        $repo = $s->container->make(AnnotationRepository::class);
        $note = $repo->list($s->acmeProject, null, null, 25, 0)[0];
        $edit = $path . '/' . $note->publicId;
        self::assertSame(200, $owner->get($edit . '/edit')->getStatusCode());
        self::assertSame(200, $owner->get($edit . '/impact')->getStatusCode());
        foreach (['days=7', 'days=bad', 'days[]=14'] as $query) {
            self::assertSame(400, $owner->get($edit . '/impact?' . $query)->getStatusCode());
        }
        $owner->get($edit . '/edit');
        self::assertSame(303, $owner->post($edit, ['title' => 'Revised title'] + $input)->getStatusCode());
        self::assertSame('Revised title', $repo->find($s->acmeProject, $note->publicId)?->title);
        $viewer = $s->loggedIn($s->viewer);
        self::assertSame(200, $viewer->get($path)->getStatusCode());
        self::assertSame(200, $viewer->get($edit . '/impact')->getStatusCode());
        foreach ([$path, $edit, $edit . '/delete'] as $post) {
            $viewer->get('/account');
            self::assertSame(403, $viewer->post($post, $input)->getStatusCode());
        }
        foreach ([$s->unassigned, $s->rivalOwner] as $user) {
            $other = $s->loggedIn($user);
            $other->get('/account');
            foreach ([$path, $edit . '/edit', $edit . '/impact'] as $get) {
                self::assertSame(404, $other->get($get)->getStatusCode());
            }
            foreach ([$path, $edit, $edit . '/delete'] as $post) {
                $other->get('/account');
                self::assertSame(404, $other->post($post, $input)->getStatusCode());
            }
        }
        $foreign = '/projects/' . $s->acmeOther->publicId . '/annotations/' . $note->publicId;
        self::assertSame(404, $owner->get($foreign . '/edit')->getStatusCode());
        self::assertSame(404, $owner->get($foreign . '/impact')->getStatusCode());
        $owner->get('/account');
        self::assertSame(404, $owner->post($foreign, $input)->getStatusCode());
        $owner->get('/account');
        self::assertSame(404, $owner->post($foreign . '/delete')->getStatusCode());
        $owner->get('/account');
        self::assertSame(303, $owner->post($edit . '/delete')->getStatusCode());
        self::assertNull($repo->find($s->acmeProject, $note->publicId));
        self::assertSame(404, $owner->get($edit . '/impact')->getStatusCode());
        self::assertContains('project.annotation_created', $s->audit()->actions());
        self::assertContains('project.annotation_updated', $s->audit()->actions());
        self::assertContains('project.annotation_removed', $s->audit()->actions());
    }
}
