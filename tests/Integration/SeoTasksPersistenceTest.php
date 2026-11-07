<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Modules\Opportunities\Tasks\TaskFilter;
use SeoControlCenter\Modules\Opportunities\Tasks\TaskInput;
use SeoControlCenter\Modules\Opportunities\Tasks\TaskRepository;

final class SeoTasksPersistenceTest extends FullStackTestCase
{
    public function testScopedWritesConcurrentCreatesSearchPaginationAndAnalysisPreservation(): void
    {
        $owner = $this->createUser('task-owner@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace'];
        $uuid = '0192f0a0-0000-7000-8000-000000000097';
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$uuid, $workspace->id]);
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($workspace->id, $uuid);
        self::assertNotNull($project);
        $opportunities = $this->app->make(OpportunityRepository::class);
        $detected = [];
        for ($i = 0; $i < 27; ++$i) {
            $detected[] = new Opportunity(OpportunityType::QuickWin, 'shoes' . $i, 'Task shoes ' . $i, 'Evidence', [], 80, 90, 1);
        }
        $opportunities->sync($project->id, $detected);
        $repo = $this->app->make(TaskRepository::class);
        $input = new TaskInput('in_progress', $owner['user']->publicId, '2026-09-01', 'Literal 100%_Ω!', 'https://shop.test/Upper?q=One');
        $opps = $opportunities->list($project->id, 'open', null, 100, 0);
        foreach ($opps as $opportunity) {
            self::assertTrue($repo->save($project, $opportunity->publicId, $input, 0));
        }
        $id = $opps[0]->publicId;
        self::assertFalse($repo->save($project, $id, $input, 0), 'Two create submissions cannot replace each other.');
        self::assertSame($input->form(), $repo->find($project, $id)?->input->form());
        $foreign = new Project($project->id, $project->publicId, $workspace->id + 1, $project->name, $project->primaryDomain, $project->timezone, $project->createdAt, $project->updatedAt);
        self::assertNull($repo->find($foreign, $id));
        self::assertFalse($repo->save($foreign, $id, $input, 1));
        self::assertFalse($repo->save($foreign, $id, $input, 0));
        self::assertSame(0, $repo->count($foreign, new TaskFilter(), '2026-10-01'));
        self::assertSame([], $repo->list($foreign, new TaskFilter(), '2026-10-01', 0));
        self::assertSame(27, $repo->count($project, new TaskFilter(overdue: true, search: '%_Ω!', priority: 50), '2026-10-01'));
        self::assertSame(0, $repo->count($project, new TaskFilter(search: '%_missing'), '2026-10-01'));
        self::assertSame(27, $repo->count($project, new TaskFilter(url: $input->url), '2026-10-01'));
        self::assertSame(0, $repo->count($project, new TaskFilter(url: 'https://shop.test/upper?q=One'), '2026-10-01'));
        self::assertSame(0, $repo->count($project, new TaskFilter(url: 'https://shop.test/Upper?q=one'), '2026-10-01'));
        self::assertSame(0, $repo->count($project, new TaskFilter(assignee: 'unassigned'), '2026-10-01'));
        self::assertCount(25, $repo->list($project, new TaskFilter(), '2026-10-01', 0));
        self::assertCount(2, $repo->list($project, new TaskFilter(), '2026-10-01', 25));
        self::assertTrue($repo->save($project, $id, new TaskInput('done'), 1));
        self::assertFalse($repo->save($project, $id, $input, 1));
        $opportunities->sync($project->id, []);
        self::assertSame('resolved', $repo->find($project, $id)?->findingStatus);
        self::assertSame('done', $repo->find($project, $id)?->input->status);
        $opportunities->sync($project->id, $detected);
        self::assertSame('open', $repo->find($project, $id)?->findingStatus);
        self::assertSame(2, $repo->find($project, $id)?->revision);
        $this->db->execute('UPDATE projects SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$project->id]);
        self::assertNull($repo->find($project, $id));
        self::assertFalse($repo->save($project, $id, $input, 2));
    }
}
