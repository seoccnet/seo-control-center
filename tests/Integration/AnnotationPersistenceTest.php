<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Annotations\AnnotationInput;
use SeoControlCenter\Modules\Annotations\AnnotationRepository;
use SeoControlCenter\Modules\Annotations\MeasurementPlanRepository;

final class AnnotationPersistenceTest extends FullStackTestCase
{
    public function testScopedPersistenceExactUrlsDatesAndSoftRemoval(): void
    {
        $owner = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace'];
        $id = '0192f0a0-0000-7000-8000-000000000092';
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$id, $workspace->id]);
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($workspace->id, $id);
        self::assertNotNull($project);
        $repo = $this->app->make(AnnotationRepository::class);
        $page = $repo->save($project, new AnnotationInput('2026-01-01', 'title', 'Title Ω', "Before\nAfter", 'https://shop.test/Page?q=One'), $owner['user']->id, null);
        $site = $repo->save($project, new AnnotationInput('2026-01-02', 'release', 'Release', '', null), $owner['user']->id, null);
        $range = new DateRange('2026-01-01', '2026-01-02');
        self::assertCount(2, $repo->list($project, $range, 'https://shop.test/Page?q=One', 25, 0));
        self::assertCount(1, $repo->list($project, $range, 'https://shop.test/page?q=One', 25, 0));
        self::assertCount(1, $repo->list($project, $range, 'https://shop.test/Page?q=one', 25, 0));
        self::assertSame(0, $repo->count($project, new DateRange('2026-01-03', '2026-01-04'), null));
        self::assertSame($site->publicId, $repo->list($project, null, null, 1, 0)[0]->publicId);
        self::assertSame($page->publicId, $repo->list($project, null, null, 1, 1)[0]->publicId);
        $changed = $repo->save($project, new AnnotationInput('2026-01-01', 'content', 'Edited', '', 'https://shop.test/new'), $owner['user']->id, $page->publicId);
        self::assertSame('Edited', $changed->title);
        self::assertSame($page->createdAt, $changed->createdAt);
        $foreign = new \SeoControlCenter\Domain\Projects\Project($project->id, $project->publicId, $workspace->id + 1, $project->name, $project->primaryDomain, $project->timezone, $project->createdAt, $project->updatedAt);
        self::assertNull($repo->find($foreign, $page->publicId));
        self::assertSame(0, $repo->count($foreign, null, null));
        $repo->remove($foreign, $page->publicId);
        self::assertNotNull($repo->find($project, $page->publicId));
        $repo->remove($project, $page->publicId);
        self::assertNull($repo->find($project, $page->publicId));
        self::assertSame(1, $repo->count($project, null, null));
        self::assertNotNull($this->db->fetchValue('SELECT deleted_at FROM seo_annotations WHERE public_id = ?', [$page->publicId]));
    }

    /** Measurement plans (ADR-042, 0.39.0): one per change, versioned, bound to the project. */
    public function testMeasurementPlansVersionFreezeClearAndCascade(): void
    {
        $owner = $this->createUser('plans@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace']->id;
        $id = '0192f0a0-0000-7000-8000-000000000a71';
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'Europe/Berlin', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$id, $workspace]);
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($workspace, $id);
        self::assertNotNull($project);
        $change = '0192f0a0-0000-7000-8000-000000000a72';
        $this->db->execute("INSERT INTO seo_annotations (public_id, workspace_id, project_id, changed_on, kind, title, note, url, created_by, created_at, updated_at) VALUES (?, ?, ?, '2026-09-01', 'title', 'New title', '', 'https://shop.test/guide', NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$change, $workspace, $project->id]);
        $repo = $this->app->make(MeasurementPlanRepository::class);
        $fields = ['objective' => 'More clicks', 'metric' => 'clicks', 'target_pages' => ['https://shop.test/guide'], 'target_queries' => ['mold on pepper'], 'control_pages' => ['https://shop.test/other'], 'days' => 28, 'goal' => 500.5, 'review_dates' => ['2026-09-15', '2026-10-03'], 'timezone' => 'Europe/Berlin'];

        self::assertNull($repo->find($project, $change));
        self::assertTrue($repo->save($project, $change, '0192f0a0-0000-7000-8000-000000000a73', $fields, 0, false, $owner['user']->id, '2026-09-02 10:00:00'));
        self::assertFalse($repo->save($project, $change, '0192f0a0-0000-7000-8000-000000000a74', $fields, 0, false, null, '2026-09-02 10:00:00'), 'One plan per change.');
        $plan = $repo->find($project, $change);
        self::assertNotNull($plan);
        self::assertSame(['0192f0a0-0000-7000-8000-000000000a73', $change, 1, ['mold on pepper'], ['2026-09-15', '2026-10-03'], 'Europe/Berlin', null], [$plan['public_id'], $plan['annotation_id'], $plan['version'], $plan['target_queries'], $plan['review_dates'], $plan['timezone'], $plan['baseline']]);
        self::assertSame(500.5, $plan['goal']);

        $baseline = ['targets' => ['from' => '2026-08-04', 'to' => '2026-08-31', 'expected_days' => 28, 'google_days' => 28, 'google_complete' => true, 'analytics_days' => 0, 'analytics_complete' => false, 'missing' => [], 'metrics' => ['clicks' => 100, 'impressions' => 2000, 'ctr' => 5.0, 'position' => 8.0, 'sessions' => null, 'conversions' => null]], 'controls' => null];
        self::assertFalse($repo->freeze($project, $change, $baseline, 0, null, '2026-09-02 11:00:00'), 'A stale version is refused.');
        self::assertTrue($repo->freeze($project, $change, $baseline, 1, $owner['user']->id, '2026-09-02 11:00:00'));
        $plan = $repo->find($project, $change);
        self::assertSame([2, '2026-09-02 11:00:00', 5.0], [$plan['version'] ?? null, $plan['frozen_at'] ?? null, $plan['baseline']['targets']['metrics']['ctr'] ?? null]);

        self::assertTrue($repo->save($project, $change, 'unused', [...$fields, 'objective' => 'Clearer objective'], 2, false, null, '2026-09-03 10:00:00'));
        self::assertNotNull($repo->find($project, $change)['baseline'] ?? null, 'Rewording keeps the baseline.');
        self::assertTrue($repo->save($project, $change, 'unused', [...$fields, 'days' => 14], 3, true, null, '2026-09-03 11:00:00'));
        self::assertNull($repo->find($project, $change)['baseline'] ?? null, 'A new setup clears it.');

        $foreign = new Project($project->id, $project->publicId, $workspace + 1, $project->name, $project->primaryDomain, $project->timezone, $project->createdAt, $project->updatedAt);
        self::assertNull($repo->find($foreign, $change), 'Never through another workspace.');
        self::assertFalse($repo->save($foreign, $change, 'unused', $fields, 4, false, null, '2026-09-04 10:00:00'));

        // 0.45.0: the results ledger lists the changes with a plan, newest change first.
        $later = '0192f0a0-0000-7000-8000-000000000a75';
        $this->db->execute("INSERT INTO seo_annotations (public_id, workspace_id, project_id, changed_on, kind, title, note, url, created_by, created_at, updated_at) VALUES (?, ?, ?, '2026-09-20', 'content', 'Rewrite', '', 'https://shop.test/guide', NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$later, $workspace, $project->id]);
        self::assertTrue($repo->save($project, $later, '0192f0a0-0000-7000-8000-000000000a76', $fields, 0, false, null, '2026-09-21 10:00:00'));
        self::assertSame([$later, $change], $repo->plannedChanges($project, 10));
        self::assertSame([$later], $repo->plannedChanges($project, 1));
        self::assertSame([], $repo->plannedChanges($foreign, 10), 'Never through another workspace.');

        $this->db->execute('UPDATE seo_annotations SET deleted_at = UTC_TIMESTAMP() WHERE public_id = ?', [$change]);
        self::assertNull($repo->find($project, $change), 'A removed change hides its plan.');
        self::assertSame([$later], $repo->plannedChanges($project, 10), 'and leaves the ledger');
        $this->db->execute('DELETE FROM projects WHERE id = ?', [$project->id]);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM measurement_plans'));
    }
}
