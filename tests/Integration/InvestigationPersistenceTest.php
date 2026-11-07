<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Domain\Investigations\InvestigationRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;

/**
 * Investigations against MySQL/MariaDB (ADR-038, D3).
 */
final class InvestigationPersistenceTest extends FullStackTestCase
{
    public function testNotebookItemsVersionsAndScope(): void
    {
        $owner = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace'];
        $id = '0192f0a0-0000-7000-8000-000000000601';
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$id, $workspace->id]);
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($workspace->id, $id);
        self::assertNotNull($project);
        $repo = $this->app->make(InvestigationRepository::class);

        $inv = $repo->create($project, ['title' => 'Drop ü', 'question' => 'Why?', 'status' => 'open', 'conclusion' => null], $owner['user']->id, null);
        $items = $repo->addItems($project, $inv, [
            ['kind' => 'source', 'ref_type' => 'url', 'ref_id' => 'https://shop.test/a', 'label' => 'Page', 'detail' => 'Read', 'observed_on' => '2026-10-15'],
            ['kind' => 'limitation', 'ref_type' => null, 'ref_id' => null, 'label' => 'No GA4', 'detail' => null, 'observed_on' => null],
        ], $owner['user']->id, 'Claude');
        self::assertCount(2, $items);
        $found = $repo->find($project, $inv);
        self::assertNotNull($found);
        self::assertSame(['Drop ü', 2, ['source' => 1, 'limitation' => 1]], [$found->title, $found->revision, $found->counts]);
        self::assertSame(['Page', '2026-10-15', 'Claude'], [$found->items[0]->label, $found->items[0]->observedOn, $found->items[0]->via]);

        $repo->update($project, $inv, ['title' => 'Drop', 'question' => null, 'status' => 'concluded', 'conclusion' => 'Forums.'], 2, null);
        try {
            $repo->update($project, $inv, ['title' => 'Again', 'question' => null, 'status' => 'open', 'conclusion' => null], 2, null);
            self::fail('A stale version must be refused.');
        } catch (HttpException $e) {
            self::assertSame(409, $e->statusCode());
        }
        self::assertTrue($repo->removeItem($project, $inv, $items[1]));
        self::assertFalse($repo->removeItem($project, $inv, $items[1]));
        $list = $repo->list($project, 'concluded', 10, 0);
        self::assertSame([$inv, ['source' => 1], 4], [$list[0]->publicId, $list[0]->counts, $list[0]->revision]);
        self::assertSame(1, $repo->count($project, null));
        self::assertSame(1, $repo->itemCount($project, $inv));

        $foreign = new Project($project->id, $project->publicId, $workspace->id + 1, $project->name, $project->primaryDomain, $project->timezone, $project->createdAt, $project->updatedAt);
        self::assertNull($repo->find($foreign, $inv));
        self::assertSame([], $repo->list($foreign, null, 10, 0));
        $this->expectException(HttpException::class);
        $repo->addItems($foreign, $inv, [['kind' => 'note', 'ref_type' => null, 'ref_id' => null, 'label' => 'x', 'detail' => null, 'observed_on' => null]], null, null);
    }
}
