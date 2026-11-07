<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\LabTests\LabResult;
use SeoControlCenter\Domain\LabTests\LabRun;
use SeoControlCenter\Domain\LabTests\LabTestRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;

/** ADR-040, F2: lab profiles and runs on MySQL, bound to one project. */
final class LabTestPersistenceTest extends FullStackTestCase
{
    public function testProfilesRunsScheduleAndRetention(): void
    {
        $owner = $this->createUser('lab@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace']->id;
        $id = '0192f0a0-0000-7000-8000-000000000401';
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$id, $workspace]);
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($workspace, $id);
        self::assertNotNull($project);
        $repo = $this->app->make(LabTestRepository::class);

        $profile = $repo->createProfile($project, 'https://shop.test/seeds', 'mobile', 'pagespeed', true, '2026-10-18 10:00:00', $owner['user']->id, '2026-10-18 09:00:00');
        self::assertNotNull($profile);
        self::assertNull($repo->createProfile($project, 'https://shop.test/seeds', 'mobile', 'pagespeed', false, null, null, '2026-10-18 09:00:00'), 'One profile per page, device and source.');
        self::assertNotNull($repo->createProfile($project, 'https://shop.test/seeds', 'desktop', 'pagespeed', false, null, null, '2026-10-18 09:00:00'));
        self::assertSame(2, $repo->countProfiles($project->id));
        $foreign = new Project($project->id, $project->publicId, $workspace + 1, $project->name, $project->primaryDomain, $project->timezone, $project->createdAt, $project->updatedAt);
        self::assertNull($repo->profile($foreign, $profile->publicId), 'Never through another workspace.');
        self::assertFalse($repo->deleteProfile($foreign, $profile->publicId));

        // A run's life: queued, running, done; a second one fails; old runs are pruned.
        $run = $repo->createRun($profile, 'manual', $owner['user']->id, '2026-10-18 09:01:00');
        self::assertTrue($repo->hasActiveRun($profile->id));
        $repo->markRunning($run->id);
        $repo->saveTask($run->id, '01234567-89ab-cdef-0123-456789abcdef', 0.005);
        $repo->polled($run->id);
        $repo->finish($run->id, new LabResult(0.81, 2500.0, 0.07, 150.0, 1000.0, 2000.0, '12.3.0'), '2026-10-18 09:02:00');
        $done = $repo->run($run->id);
        self::assertSame([LabRun::DONE, 1, 0.005, 81, '12.3.0'], [$done?->status, $done?->polls, $done?->cost, $done?->result?->present()['score'], $done?->result?->version]);
        self::assertFalse($repo->hasActiveRun($profile->id));
        $failed = $repo->createRun($profile, 'weekly', null, '2026-10-18 09:03:00');
        $repo->fail($failed->id, 'PageSpeed refused the run.', '2026-10-18 09:04:00');
        $repo->finish($failed->id, new LabResult(0.5, 1.0, 0.0, null, null, null, null), '2026-10-18 09:05:00');
        self::assertSame([LabRun::FAILED, null], [$repo->run($failed->id)?->status, $repo->run($failed->id)?->result], 'A finished run is not overwritten.');
        $stale = $repo->createRun($profile, 'manual', null, '2026-10-18 05:00:00');
        $repo->failStale('2026-10-18 07:00:00', '2026-10-18 09:06:00');
        self::assertSame(LabRun::FAILED, $repo->run($stale->id)?->status);
        self::assertSame([$stale->id, $failed->id, $run->id], array_map(static fn(LabRun $r): int => $r->id, $repo->recentRuns($project->id, 8)[$profile->id]), 'Newest first.');
        self::assertCount(2, $repo->recentRuns($project->id, 2)[$profile->id]);
        $repo->prune($profile->id, 2);
        self::assertNull($repo->run($run->id), 'Only the newest two finished runs are kept.');

        // Weekly scheduling.
        self::assertSame([$profile->id], array_map(static fn($p): int => $p->id, $repo->due('2026-10-18 10:00:00', 10)));
        $repo->scheduleNext($profile->id, '2026-10-25 10:00:00');
        self::assertSame([], $repo->due('2026-10-18 10:00:00', 10));
        self::assertTrue($repo->setWeekly($project, $profile->publicId, false, null));
        self::assertSame([], $repo->due('2026-11-30 00:00:00', 10));
        $this->db->execute('UPDATE workspaces SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$workspace]);
        $repo->setWeekly($project, $profile->publicId, true, '2026-10-18 00:00:00');
        self::assertSame([], $repo->due('2026-11-30 00:00:00', 10), 'Deleted workspaces are never scheduled.');

        self::assertTrue($repo->deleteProfile($project, $profile->publicId));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM lab_runs WHERE profile_id = ?', [$profile->id]), 'Runs go with their profile.');
    }
}
