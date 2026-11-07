<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Fakes\InMemoryBusinessEvents;
use SeoControlCenter\Tests\Support\Scenario;

/** Business events (ADR-042) stay inside their project and workspace. */
final class BusinessMeasurementIsolationTest extends TestCase
{
    public function testForeignProjectsAndEventsAreNotFound(): void
    {
        $s = new Scenario();
        $events = $s->container->make(InMemoryBusinessEvents::class);
        $rivalEvent = $events->add($s->rival->id, $s->rivalProject->id, 'purchase', 'purchase', 'Rival order', null);
        $ownEvent = $events->add($s->acme->id, $s->acmeProject->id, 'purchase', 'purchase', 'Order', null);
        $owner = $s->loggedIn($s->owner);
        $member = $s->loggedIn($s->member);

        foreach ([$s->acmeOther, $s->rivalProject] as $project) {
            $base = '/projects/' . $project->publicId . '/analytics/events';
            $member->get('/');
            self::assertSame(404, $member->post($base, ['kind' => 'purchase', 'event_name' => 'purchase'])->getStatusCode());
            $member->get('/');
            self::assertSame(404, $member->post($base . '/check')->getStatusCode());
        }
        $owner->get('/');
        self::assertSame(404, $owner->post('/projects/' . $s->rivalProject->publicId . '/analytics/events/' . $rivalEvent->publicId . '/delete')->getStatusCode());
        $owner->get('/');
        self::assertSame(404, $owner->post('/projects/' . $s->acmeProject->publicId . '/analytics/events/' . $rivalEvent->publicId . '/delete')->getStatusCode(), 'Another project\'s event ID is not found in this project.');
        self::assertCount(1, $events->events($s->rivalProject->id));
        self::assertStringNotContainsString('Rival order', (string) $owner->get('/projects/' . $s->acmeProject->publicId . '/analytics')->getBody());
        self::assertSame('Order', $events->events($s->acmeProject->id)[0]->label);
        self::assertNotSame($ownEvent->publicId, $rivalEvent->publicId);
    }
}
