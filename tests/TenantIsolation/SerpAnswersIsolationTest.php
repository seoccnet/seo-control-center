<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Scenario;

/** The featured snippet and "People also ask" page (1.5.0) of foreign or unassigned projects is not found. */
final class SerpAnswersIsolationTest extends TestCase
{
    public function testForeignAndUnassignedProjectsAreNotFound(): void
    {
        $s = new Scenario();
        $member = $s->loggedIn($s->member);
        foreach ([$s->rivalProject, $s->acmeOther] as $project) {
            self::assertSame(404, $member->get('/projects/' . $project->publicId . '/rankings/answers')->getStatusCode());
        }
        self::assertSame(200, $member->get('/projects/' . $s->acmeProject->publicId . '/rankings/answers')->getStatusCode());
    }
}
