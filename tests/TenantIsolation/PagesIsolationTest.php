<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Scenario;

final class PagesIsolationTest extends TestCase
{
    public function testPaidMonitoringSettingsRequireVisibleProjectAndPermissions(): void
    {
        $s = new Scenario();
        $url = '/projects/' . $s->acmeProject->publicId . '/backlinks/monitoring';
        $viewer = $s->loggedIn($s->viewer);
        $viewer->get('/projects/' . $s->acmeProject->publicId . '/backlinks');
        self::assertSame(403, $viewer->post($url, ['monitor_lost' => '1'])->getStatusCode());
        $rival = $s->loggedIn($s->rivalOwner);
        $rival->get('/');
        self::assertSame(404, $rival->post($url, ['monitor_lost' => '1'])->getStatusCode());
        $member = $s->loggedIn($s->member);
        $member->get('/');
        self::assertSame(404, $member->post('/projects/' . $s->acmeOther->publicId . '/backlinks/monitoring', ['monitor_lost' => '1'])->getStatusCode());
    }

    public function testExportsCannotBeRequestedOrDownloadedAcrossProjects(): void
    {
        $s = new Scenario();
        $pages = $s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemoryPages::class);
        $exports = $s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemoryPageExports::class);
        foreach ([$s->rivalProject, $s->acmeOther] as $project) {
            $pages->pages[$project->id][] = new \SeoControlCenter\Modules\Pages\PageSummary('https://foreign.test/page', null, null);
        }
        $rivalExport = $exports->create($s->rivalProject->id, $s->rivalProject->workspaceId, $s->rivalOwner->id, '', 'url', '2026-01-01', '2026-01-28', 1);
        $member = $s->loggedIn($s->member);
        foreach ([$s->rivalProject, $s->acmeOther] as $project) {
            $member->get('/account');
            $base = '/projects/' . $project->publicId . '/pages';
            self::assertSame(404, $member->post($base . '/exports', ['sort' => 'url'])->getStatusCode());
            self::assertSame(404, $member->get($base . '/exports/' . $rivalExport->publicId)->getStatusCode());
        }
        // The rival's export ID is not reachable through a project the member can see.
        self::assertSame(404, $member->get('/projects/' . $s->acmeProject->publicId . '/pages/exports/' . $rivalExport->publicId)->getStatusCode());
        self::assertCount(1, $exports->exports);
        $unassigned = $s->loggedIn($s->unassigned);
        $unassigned->get('/account');
        self::assertSame(404, $unassigned->post('/projects/' . $s->acmeProject->publicId . '/pages/exports', ['sort' => 'url'])->getStatusCode());
        self::assertSame(419, $s->loggedIn($s->owner)->post('/projects/' . $s->acmeProject->publicId . '/pages/exports', ['sort' => 'url'], withCsrf: false)->getStatusCode());
        self::assertCount(1, $exports->exports);
    }

    public function testForeignAndUnassignedProjectsAreNotFound(): void
    {
        $s = new Scenario();
        foreach ([$s->rivalProject, $s->acmeOther] as $project) {
            $browser = $s->loggedIn($s->member);
            $base = '/projects/' . $project->publicId . '/pages';
            self::assertSame(404, $browser->get($base)->getStatusCode());
            self::assertSame(404, $browser->get($base . '/details?url=' . rawurlencode('https://rival.test/'))->getStatusCode());
        }
        self::assertSame(404, $s->loggedIn($s->unassigned)->get('/projects/' . $s->acmeProject->publicId . '/pages')->getStatusCode());
    }
}
