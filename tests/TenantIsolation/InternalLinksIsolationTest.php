<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Fakes\InMemoryLinkGraph;
use SeoControlCenter\Tests\Support\Fakes\InMemorySiteAudit;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Internal links of another workspace or an unassigned project answer 404
 * and never appear in another project's page (ADR-040, F1).
 */
final class InternalLinksIsolationTest extends TestCase
{
    public function testForeignLinkGraphsAreNotFound(): void
    {
        $s = new Scenario();
        $audits = $s->container->get(InMemorySiteAudit::class);
        $graph = $s->container->get(InMemoryLinkGraph::class);
        self::assertInstanceOf(InMemorySiteAudit::class, $audits);
        self::assertInstanceOf(InMemoryLinkGraph::class, $graph);
        $audit = $audits->create($s->rivalProject->id, $s->rival->id, 'https://rival.test/', 100, 'manual', null);
        $audits->finish($audit->id, 'completed', 90, ['error' => 0, 'warning' => 0, 'notice' => 0], [], null);
        $graph->pages[$audit->id] = [1 => ['url' => 'https://rival.test/a', 'inbound' => 0], 2 => ['url' => 'https://rival.test/b', 'inbound' => 0]];
        $graph->replaceSuggestions($audit->id, $s->rivalProject->id, [['source_page_id' => 1, 'target_page_id' => 2, 'cluster_id' => null, 'topic' => 'rival secret topic', 'anchor' => 'secret', 'paragraph' => 'A rival secret paragraph.', 'score' => 1.0]], '2026-10-17 10:00:00');

        $owner = $s->loggedIn($s->owner);
        self::assertSame(404, $owner->get('/projects/' . $s->rivalProject->publicId . '/site-audit/links')->getStatusCode());
        self::assertSame(404, $s->loggedIn($s->unassigned)->get('/projects/' . $s->acmeProject->publicId . '/site-audit/links')->getStatusCode());
        $own = (string) $owner->get('/projects/' . $s->acmeProject->publicId . '/site-audit/links')->getBody();
        self::assertStringNotContainsString('rival secret', $own);
        self::assertStringContainsString('No completed site audit yet', $own);
    }
}
