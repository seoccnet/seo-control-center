<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\SerpLandscape;
use SeoControlCenter\Modules\Clusters\ClusterService;
use SeoControlCenter\Tests\Support\Fakes\InMemoryAnalyses;
use SeoControlCenter\Tests\Support\Fakes\InMemorySerpLandscapes;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * "Why they outrank you" routes answer 404 for topics of another workspace
 * or an unassigned project, and never queue an analysis there.
 */
final class ComparisonIsolationTest extends TestCase
{
    public function testForeignTopicsAreNotFound(): void
    {
        $s = new Scenario();
        $members = ['rival guide', 'rival growing guide'];
        $s->container->make(ClusterService::class)->refresh($s->rivalProject->id, $s->rival->id, 'rival.test', array_fill_keys($members, ['label' => 'rival guide', 'size' => 2, 'members' => $members]), ['rival guide' => 100.0, 'rival growing guide' => 50.0], new ProjectSignals());
        $rivalCluster = $s->container->make(ClusterService::class)->listing($s->rivalProject, ['show' => 'all'])['items'][0]->publicId;
        $serps = $s->container->get(InMemorySerpLandscapes::class);
        self::assertInstanceOf(InMemorySerpLandscapes::class, $serps);
        $serps->serps[$s->rivalProject->id]['desktop']['rival guide'] = new SerpLandscape('rival guide', $s->container->make(Clock::class)->now()->format('Y-m-d'), [['position' => 1, 'domain' => 'a.test', 'url' => 'https://a.test/x', 'title' => 'x']], [], false);

        $owner = $s->loggedIn($s->owner);
        foreach ([$s->rivalProject->publicId . '/keywords/topics/' . $rivalCluster, $s->acmeProject->publicId . '/keywords/topics/' . $rivalCluster] as $path) {
            self::assertSame(404, $owner->get('/projects/' . $path . '/analysis')->getStatusCode(), $path);
            self::assertSame(404, $owner->get('/projects/' . $path . '/analysis/report')->getStatusCode(), $path);
            $owner->get('/projects/' . $s->acmeProject->publicId . '/annotations');
            self::assertSame(404, $owner->post('/projects/' . $path . '/analysis', [])->getStatusCode(), $path);
        }
        self::assertSame(404, $s->loggedIn($s->unassigned)->get('/projects/' . $s->acmeProject->publicId . '/keywords/topics/' . $rivalCluster . '/analysis')->getStatusCode());
        self::assertSame([], $s->container->get(InMemoryAnalyses::class)->rows);
        self::assertSame(200, $s->loggedIn($s->rivalOwner)->get('/projects/' . $s->rivalProject->publicId . '/keywords/topics/' . $rivalCluster . '/analysis')->getStatusCode());
    }
}
