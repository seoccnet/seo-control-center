<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Scenario;

final class Ga4IsolationTest extends TestCase
{
    public function testWorkspaceCredentialsAndProjectRoutesStayProtected(): void
    {
        $s = new Scenario();
        $viewer = $s->loggedIn($s->viewer);
        self::assertSame(403, $viewer->get('/integrations/ga4')->getStatusCode());
        self::assertSame(200, $viewer->get('/projects/' . $s->acmeProject->publicId . '/analytics')->getStatusCode());
        $viewer->get('/');
        self::assertSame(403, $viewer->post('/projects/' . $s->acmeProject->publicId . '/analytics', ['property_id' => '123'])->getStatusCode());
        $viewer->get('/');
        self::assertSame(403, $viewer->post('/projects/' . $s->acmeProject->publicId . '/analytics/import')->getStatusCode());
        $member = $s->loggedIn($s->member);
        foreach ([$s->acmeOther, $s->rivalProject] as $project) {
            self::assertSame(404, $member->get('/projects/' . $project->publicId . '/analytics')->getStatusCode());
            $member->get('/');
            self::assertSame(404, $member->post('/projects/' . $project->publicId . '/analytics', ['property_id' => '123'])->getStatusCode());
            $member->get('/');
            self::assertSame(404, $member->post('/projects/' . $project->publicId . '/analytics/import')->getStatusCode());
        }
        $owner = $s->loggedIn($s->owner);
        $owner->get('/');
        self::assertSame(419, $owner->post('/integrations/ga4', ['credentials' => ''], withCsrf: false)->getStatusCode());
    }
}
