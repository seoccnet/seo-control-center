<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;
use SeoControlCenter\Tests\Support\Scenario;

final class RankingImportIsolationTest extends TestCase
{
    public function testForeignAndUnassignedProjectsAreNotFound(): void
    {
        $s = new Scenario();
        $member = $s->loggedIn($s->member);
        $member->get('/account');
        $csrf = $member->csrfToken();
        foreach ([$s->rivalProject, $s->acmeOther] as $project) {
            $url = '/projects/' . $project->publicId . '/rankings/import';
            self::assertSame(404, $member->get($url)->getStatusCode());
            self::assertSame(404, $member->post($url, ['_csrf' => $csrf, 'market' => '2840:en', 'device' => 'desktop', 'date' => '2026-11-01'])->getStatusCode());
        }
        self::assertSame([], $s->container->get(InMemoryKeywords::class)->keywords);
    }
}
