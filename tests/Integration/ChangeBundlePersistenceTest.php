<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Modules\ChangeBundles\ChangeBundleRepository;

/** Change bundles (ADR-042, 0.40.0) on MySQL: versioned state and history, bound to the project. */
final class ChangeBundlePersistenceTest extends FullStackTestCase
{
    public function testVersionedStateEventsAndCascade(): void
    {
        $owner = $this->createUser('bundles@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace']->id;
        $pid = '0192f0a0-0000-7000-8000-000000000b71';
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$pid, $workspace]);
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($workspace, $pid);
        self::assertNotNull($project);
        $repo = $this->app->make(ChangeBundleRepository::class);
        $id = '0192f0a0-0000-7000-8000-000000000b72';
        $data = ['problem' => 'Low CTR Ω', 'edits' => [['field' => 'title', 'before' => 'a', 'after' => 'b']], 'baseline' => ['status' => 200, 'noindex' => false, 'title' => null]];

        $repo->create($project, $id, 'Bundle', 'https://shop.test/a', 'draft', $data, $owner['user']->id, '2026-10-24 10:00:00');
        $bundle = $repo->find($project, $id);
        self::assertNotNull($bundle);
        self::assertSame(['Bundle', 'draft', 1, $data], [$bundle['title'], $bundle['status'], $bundle['version'], $bundle['data']]);
        self::assertSame(1, $repo->countInProject($project->id));

        self::assertFalse($repo->update($project, $id, 'Bundle', 'https://shop.test/a', 'approved', $data, 0, '2026-10-24 11:00:00'), 'A stale version is refused.');
        self::assertTrue($repo->update($project, $id, 'Bundle', 'https://shop.test/a', 'approved', [...$data, 'approved_hash' => str_repeat('a', 64)], 1, '2026-10-24 11:00:00'));
        self::assertSame(['approved', 2, str_repeat('a', 64)], [$repo->find($project, $id)['status'] ?? null, $repo->find($project, $id)['version'] ?? null, $repo->find($project, $id)['data']['approved_hash'] ?? null]);

        $repo->addEvent($project, $id, 'created', 'Draft created.', $owner['user']->id, '2026-10-24 10:00:00');
        $repo->addEvent($project, $id, 'approved', 'Approved.', null, '2026-10-24 11:00:00');
        self::assertSame(['approved', 'created'], array_column($repo->events($project, $id), 'kind'));
        self::assertSame([$id], array_column($repo->list($project, 10), 'public_id'));

        $foreign = new Project($project->id, $project->publicId, $workspace + 1, $project->name, $project->primaryDomain, $project->timezone, $project->createdAt, $project->updatedAt);
        self::assertNull($repo->find($foreign, $id), 'Never through another workspace.');
        self::assertSame([], $repo->list($foreign, 10));
        self::assertSame([], $repo->events($foreign, $id));
        self::assertFalse($repo->update($foreign, $id, 'Taken', 'https://shop.test/a', 'draft', [], 2, '2026-10-24 12:00:00'));
        $repo->addEvent($foreign, $id, 'injected', 'Not here.', null, '2026-10-24 12:00:00');
        self::assertCount(2, $repo->events($project, $id));

        $this->db->execute('DELETE FROM projects WHERE id = ?', [$project->id]);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM change_bundles') + (int) $this->db->fetchValue('SELECT COUNT(*) FROM change_bundle_events'));
    }
}
