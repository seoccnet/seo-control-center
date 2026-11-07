<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\LabTests\LabResult;
use SeoControlCenter\Domain\LabTests\LabTestRepository;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Tests\Unit\LabTests\LabDiagnosticsTest;

/** Speed diagnostics (ADR-042, 0.38.0) are stored with the run and read back unchanged. */
final class LabDiagnosticsDbTest extends FullStackTestCase
{
    public function testDiagnosticsRoundTripAndOlderRunsStayWithout(): void
    {
        $owner = $this->createUser('labdiag@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace']->id;
        $id = '0192f0a0-0000-7000-8000-000000000d01';
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$id, $workspace]);
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($workspace, $id);
        self::assertNotNull($project);
        $repo = $this->app->make(LabTestRepository::class);
        $profile = $repo->createProfile($project, 'https://shop.test/seeds', 'mobile', 'pagespeed', false, null, null, '2026-10-20 09:00:00');
        self::assertNotNull($profile);

        $old = $repo->createRun($profile, 'manual', null, '2026-10-20 09:01:00');
        $repo->finish($old->id, new LabResult(0.8, 2600.0, 0.05, null, null, null, '11.4.0'), '2026-10-20 09:02:00');
        self::assertNull($repo->run($old->id)?->result?->diagnostics);

        $result = LabResult::fromLighthouse(LabDiagnosticsTest::report(), 'https://shop.test/seeds', 'mobile');
        self::assertNotNull($result->diagnostics);
        $run = $repo->createRun($profile, 'manual', null, '2026-10-20 10:01:00');
        $repo->finish($run->id, $result, '2026-10-20 10:02:00');
        $stored = $repo->run($run->id)?->result;
        self::assertNotNull($stored);
        self::assertEquals($result->diagnostics->present(), $stored->diagnostics?->present());
        self::assertSame('Lighthouse 12.3.0 and 11.4.0 score differently', $stored->incompatibleWith($repo->recentRuns($project->id, 8)[$profile->id][1]->result ?? $stored));
        self::assertNull($stored->incompatibleWith($stored));
    }
}
