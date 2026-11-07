<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\ChangeBundles\ChangeBundleService;
use SeoControlCenter\Tests\Support\Fakes\InMemoryChangeBundles;
use SeoControlCenter\Tests\Support\Scenario;

/** Change bundles (ADR-042) stay inside their project and workspace. */
final class ChangeBundlesIsolationTest extends TestCase
{
    public function testForeignBundlesAreNotFoundAndForeignChecksDoNothing(): void
    {
        $s = new Scenario();
        $bundles = $s->container->make(InMemoryChangeBundles::class);
        $id = '0192f0a0-0000-7000-8000-000000000b61';
        $data = ['problem' => 'Rival problem', 'evidence' => '', 'edits' => [['field' => 'title', 'before' => 'a', 'after' => 'b']], 'expected' => 'E', 'validation' => 'V', 'pending' => 'baseline'];
        $bundles->create($s->rivalProject, $id, 'Rival bundle', 'https://rival.test/page', 'draft', $data, null, '2026-10-24 10:00:00');
        $member = $s->loggedIn($s->member);
        foreach ([$s->rivalProject, $s->acmeProject, $s->acmeOther] as $project) {
            $base = '/projects/' . $project->publicId . '/change-bundles';
            $expected = $project === $s->acmeProject ? 200 : 404;
            self::assertSame($expected, $member->get($base)->getStatusCode());
            self::assertSame(404, $member->get($base . '/' . $id)->getStatusCode());
            foreach (['/approve', '/check', '/deploy', '/rollback', '/delete', ''] as $action) {
                $member->get('/account');
                self::assertSame(404, $member->post($base . '/' . $id . $action, ['version' => '1', 'purpose' => 'baseline', 'note' => 'Take over'])->getStatusCode(), $project->name . $action);
            }
        }
        self::assertStringNotContainsString('Rival bundle', (string) $member->get('/projects/' . $s->acmeProject->publicId . '/change-bundles')->getBody());
        // A job naming another project's ID does not touch the rival bundle.
        $bundles->live['https://rival.test/page'] = ['status' => 200];
        $s->container->make(ChangeBundleService::class)->runCheck($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $id, 'baseline');
        self::assertSame(1, $bundles->bundles[$s->rivalProject->id][$id]['version']);
        self::assertArrayNotHasKey('baseline', $bundles->bundles[$s->rivalProject->id][$id]['data']);
    }
}
