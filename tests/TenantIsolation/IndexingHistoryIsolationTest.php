<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Fakes\InMemoryIndexing;
use SeoControlCenter\Tests\Support\Fakes\InMemoryIndexingEvidence;
use SeoControlCenter\Tests\Support\Scenario;

/** Indexing history and recorded requests (ADR-042) stay inside their project and workspace. */
final class IndexingHistoryIsolationTest extends TestCase
{
    public function testForeignProjectsAreNotFoundAndTheirEvidenceStaysHidden(): void
    {
        $s = new Scenario();
        $url = 'https://shop.acme.test/guide';
        $s->container->make(InMemoryIndexingEvidence::class)->crawls[$s->rivalProject->id][$url] = ['status_code' => 200, 'noindex' => true, 'canonical' => null, 'redirect_to' => null, 'error' => null, 'crawled_at' => '2026-01-01 08:00:00'];
        $s->container->make(InMemoryIndexing::class)->addRequest($s->rival->id, $s->rivalProject->id, '0192f0a0-0000-7000-8000-0000000000f1', $url, 'search_console', '2026-01-01', 'Rival evidence', '', null, '2026-01-01 09:00:00');
        $member = $s->loggedIn($s->member);
        foreach ([$s->acmeOther, $s->rivalProject] as $project) {
            self::assertSame(404, $member->get('/projects/' . $project->publicId . '/indexing/history?url=' . rawurlencode($url))->getStatusCode());
            $member->get('/account');
            self::assertSame(404, $member->post('/projects/' . $project->publicId . '/indexing/requests', ['url' => $url, 'channel' => 'search_console', 'requested_on' => '2026-01-01', 'evidence' => 'Indexing requested'])->getStatusCode());
        }
        $html = (string) $s->loggedIn($s->owner)->get('/projects/' . $s->acmeProject->publicId . '/indexing/history?url=' . rawurlencode($url))->getBody();
        self::assertStringNotContainsString('Rival evidence', $html);
        self::assertStringNotContainsString('HTTP 200', $html);
        self::assertSame([], $s->container->make(InMemoryIndexing::class)->requests($s->acmeProject->id, $url));
    }
}
