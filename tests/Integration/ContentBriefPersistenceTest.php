<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Modules\ContentBriefs\BriefInput;
use SeoControlCenter\Modules\ContentBriefs\BriefRepository;

final class ContentBriefPersistenceTest extends FullStackTestCase
{
    public function testScopesImmutableEvidenceOptimisticWritesSearchAndRemoval(): void
    {
        $owner = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace'];
        $uuid = '0192f0a0-0000-7000-8000-000000000093';
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$uuid, $workspace->id]);
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($workspace->id, $uuid);
        self::assertNotNull($project);
        $repo = $this->app->make(BriefRepository::class);
        $input = BriefInput::fromArray(['title' => 'Brief 100%_Ω!', 'keyword' => 'shoes', 'status' => 'draft', 'url' => 'https://shop.test/Upper?q=One', 'outline' => "H1: Ω\nH2: Details"], $project);
        $brief = $repo->create($project, $input, ['Snapshot Ω', 'Missing metrics: unavailable'], $owner['user']->id);
        self::assertSame($input->form(), $repo->find($project, $brief->publicId)?->input->form());
        self::assertSame(1, $repo->count($project, '', '%_Ω!'));
        self::assertSame(0, $repo->count($project, 'published', ''));
        self::assertSame(1, $repo->count($project, '', 'Upper?q=One'));
        $foreign = new Project($project->id, $project->publicId, $workspace->id + 1, $project->name, $project->primaryDomain, $project->timezone, $project->createdAt, $project->updatedAt);
        self::assertNull($repo->find($foreign, $brief->publicId));
        self::assertSame(0, $repo->count($foreign, '', ''));
        self::assertSame([], $repo->list($foreign, '', '', 25, 0));
        self::assertFalse($repo->update($foreign, $brief->publicId, $input, 1));
        self::assertFalse($repo->remove($foreign, $brief->publicId, 1));
        $changed = BriefInput::fromArray(['title' => 'Edited', 'keyword' => 'new shoes', 'status' => 'published'], $project);
        self::assertTrue($repo->update($project, $brief->publicId, $changed, 1));
        self::assertFalse($repo->update($project, $brief->publicId, $input, 1));
        $saved = $repo->find($project, $brief->publicId);
        self::assertNotNull($saved);
        self::assertSame('Edited', $saved->input->title);
        self::assertSame($brief->evidence, $saved->evidence);
        self::assertSame($brief->createdAt, $saved->createdAt);
        self::assertNull($brief->publishedAt);
        self::assertNotNull($saved->publishedAt, 'Publishing records the date.');
        self::assertTrue($repo->update($project, $brief->publicId, BriefInput::fromArray(['title' => 'Edited again', 'keyword' => 'new shoes', 'status' => 'published'], $project), 2));
        self::assertSame($saved->publishedAt, $repo->find($project, $brief->publicId)?->publishedAt, 'Edits keep the first publication date.');
        self::assertTrue($repo->update($project, $brief->publicId, BriefInput::fromArray(['title' => 'Edited', 'keyword' => 'new shoes', 'status' => 'draft'], $project), 3));
        self::assertNull($repo->find($project, $brief->publicId)?->publishedAt, 'Leaving Published clears it.');
        self::assertTrue($repo->update($project, $brief->publicId, $changed, 4));
        $saved = $repo->find($project, $brief->publicId);
        self::assertNotNull($saved);
        self::assertSame(5, $saved->revision);
        for ($i = 0; $i < 26; ++$i) {
            $repo->create($project, $input, [], $owner['user']->id);
        }
        self::assertCount(25, $repo->list($project, '', '', 25, 0));
        self::assertCount(2, $repo->list($project, '', '', 25, 25));
        self::assertFalse($repo->remove($project, $brief->publicId, 1));
        self::assertTrue($repo->remove($project, $brief->publicId, 5));
        self::assertNull($repo->find($project, $brief->publicId));
        self::assertNotNull($this->db->fetchValue('SELECT deleted_at FROM content_briefs WHERE public_id = ?', [$brief->publicId]));
        self::assertSame(26, $repo->count($project, '', ''));
        $this->expectException(\RuntimeException::class);
        $repo->create($foreign, $input, [], $owner['user']->id);
    }
}
