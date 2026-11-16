<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Scenario;

/** The Google update report (1.6.0) of foreign or unassigned projects is not found. */
final class UpdateImpactIsolationTest extends TestCase
{
    public function testForeignAndUnassignedProjectsAreNotFound(): void
    {
        $s = new Scenario();
        $member = $s->loggedIn($s->member);
        foreach ([$s->rivalProject, $s->acmeOther] as $project) {
            self::assertSame(404, $member->get('/projects/' . $project->publicId . '/performance/updates?update=march-2026-core-update')->getStatusCode());
        }
        self::assertSame(200, $member->get('/projects/' . $s->acmeProject->publicId . '/performance/updates')->getStatusCode());
    }
}
