<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Modules\Investigations\InvestigationService;
use SeoControlCenter\Tests\Support\Fakes\InMemoryInvestigations;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Investigations of another workspace, or of a project the member is not
 * assigned to, answer 404 and are never changed.
 */
final class InvestigationsIsolationTest extends TestCase
{
    public function testForeignInvestigationsAreNotFound(): void
    {
        $s = new Scenario();
        $rival = $s->container->make(InvestigationService::class)->save($s->rivalOwner, $s->rivalProject, ['title' => 'Rival notes'], ClientInfo::cli());
        $owner = $s->loggedIn($s->owner);
        $owner->get('/projects/' . $s->acmeProject->publicId . '/investigations');
        foreach ([$s->rivalProject->publicId, $s->acmeProject->publicId] as $project) {
            $path = '/projects/' . $project . '/investigations/' . $rival->publicId;
            self::assertSame(404, $owner->get($path)->getStatusCode(), $path);
            $owner->get('/projects/' . $s->acmeProject->publicId . '/investigations');
            self::assertSame(404, $owner->post($path, ['title' => 'Taken', 'version' => '1'])->getStatusCode(), $path);
            $owner->get('/projects/' . $s->acmeProject->publicId . '/investigations');
            self::assertSame(404, $owner->post($path . '/items', ['from' => 'manual', 'kind' => 'note', 'label' => 'x'])->getStatusCode(), $path);
        }
        self::assertSame(404, $owner->get('/projects/' . $s->rivalProject->publicId . '/investigations')->getStatusCode());
        self::assertSame(404, $s->loggedIn($s->unassigned)->get('/projects/' . $s->acmeProject->publicId . '/investigations')->getStatusCode());
        $store = $s->container->get(InMemoryInvestigations::class);
        self::assertInstanceOf(InMemoryInvestigations::class, $store);
        self::assertSame(['Rival notes', [], 1], [$store->rows[$rival->publicId]['title'], $store->rows[$rival->publicId]['items'], $store->rows[$rival->publicId]['revision']]);
    }
}
