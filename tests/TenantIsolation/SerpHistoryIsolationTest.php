<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Fakes\InMemorySerpEvents;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Results-page changes of another workspace or of an unassigned project
 * answer 404 and never appear in another project's list.
 */
final class SerpHistoryIsolationTest extends TestCase
{
    public function testForeignChangesAreNotFound(): void
    {
        $s = new Scenario();
        $events = $s->container->get(InMemorySerpEvents::class);
        self::assertInstanceOf(InMemorySerpEvents::class, $events);
        $events->record($s->rivalProject->id, $s->rival->id, 'desktop', '2026-10-12', [['keyword_id' => 999, 'previous_on' => '2026-10-05', 'kind' => 'feature_added', 'subject' => 'ai_overview', 'detail' => []]]);
        $owner = $s->loggedIn($s->owner);
        self::assertSame(404, $owner->get('/projects/' . $s->rivalProject->publicId . '/rankings/changes')->getStatusCode());
        self::assertSame(404, $s->loggedIn($s->unassigned)->get('/projects/' . $s->acmeProject->publicId . '/rankings/changes')->getStatusCode());
        self::assertStringContainsString('0 changes', (string) $owner->get('/projects/' . $s->acmeProject->publicId . '/rankings/changes')->getBody());
    }
}
