<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Fakes\InMemoryLinkTargets;
use SeoControlCenter\Tests\Support\Scenario;

/** Links to missing pages (1.5.0) of foreign or unassigned projects are not found and cannot be checked. */
final class LinkReclamationIsolationTest extends TestCase
{
    public function testForeignAndUnassignedProjectsAreNotFound(): void
    {
        $s = new Scenario();
        $member = $s->loggedIn($s->member);
        $member->get('/account');
        $csrf = $member->csrfToken();
        foreach ([$s->rivalProject, $s->acmeOther] as $project) {
            $url = '/projects/' . $project->publicId . '/backlinks/reclaim';
            self::assertSame(404, $member->get($url)->getStatusCode());
            self::assertSame(404, $member->post($url, ['_csrf' => $csrf])->getStatusCode());
        }
        $targets = $s->container->get(InMemoryLinkTargets::class);
        self::assertInstanceOf(InMemoryLinkTargets::class, $targets);
        self::assertSame([], $targets->runs);
    }
}
