<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Scenario;

/** The snippet preview (1.6.0) of foreign or unassigned projects is not found. */
final class SnippetPreviewIsolationTest extends TestCase
{
    public function testForeignAndUnassignedProjectsAreNotFound(): void
    {
        $s = new Scenario();
        $member = $s->loggedIn($s->member);
        foreach ([$s->rivalProject, $s->acmeOther] as $project) {
            self::assertSame(404, $member->get('/projects/' . $project->publicId . '/site-audit/snippets?try=1&title=x')->getStatusCode());
        }
        self::assertSame(200, $member->get('/projects/' . $s->acmeProject->publicId . '/site-audit/snippets')->getStatusCode());
    }
}
