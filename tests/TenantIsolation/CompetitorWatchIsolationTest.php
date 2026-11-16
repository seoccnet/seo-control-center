<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Fakes\InMemoryCompetitorWatch;
use SeoControlCenter\Tests\Support\Scenario;

/** Competitor content watch (1.6.0) of foreign or unassigned projects is not found and cannot be switched on. */
final class CompetitorWatchIsolationTest extends TestCase
{
    public function testForeignAndUnassignedProjectsAreNotFound(): void
    {
        $s = new Scenario();
        $member = $s->loggedIn($s->member);
        $member->get('/account');
        $csrf = $member->csrfToken();
        foreach ([$s->rivalProject, $s->acmeOther] as $project) {
            $url = '/projects/' . $project->publicId . '/competitors/new-pages';
            self::assertSame(404, $member->get($url)->getStatusCode());
            self::assertSame(404, $member->post($url . '/watch', ['_csrf' => $csrf, 'enabled' => '1'])->getStatusCode());
        }
        $watch = $s->container->get(InMemoryCompetitorWatch::class);
        self::assertInstanceOf(InMemoryCompetitorWatch::class, $watch);
        self::assertSame([], $watch->enabled);
    }
}
