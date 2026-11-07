<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\ArticleIdeas\ArticleIdea;
use SeoControlCenter\Domain\ArticleIdeas\ArticleTracking;
use SeoControlCenter\Domain\ArticleIdeas\ArticleTrackingRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;

/** ADR-041, G3: published articles from ideas on MySQL, one per idea, bound to one project. */
final class PublishedArticleStoreTest extends FullStackTestCase
{
    public function testSnapshotResultsAndDue(): void
    {
        $owner = $this->createUser('published@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace']->id;
        $id = '0192f0a0-0000-7000-8000-000000000701';
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$id, $workspace]);
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($workspace, $id);
        self::assertNotNull($project);
        $repo = $this->app->make(ArticleTrackingRepository::class);
        $idea = new ArticleIdea(str_repeat('c', 64), ArticleIdea::UPDATE, 'mold on pepper ✓', 1000, [], [], null, [
            'traffic' => ['low' => 20, 'expected' => 60, 'high' => 150],
            'difficulty' => ['level' => 'medium', 'reasons' => []],
            'backlinks' => null,
            'months' => ['low' => 2, 'high' => 4, 'basis' => 'An assumption.'],
        ], 1.0, []);
        $prediction = ['traffic' => ['low' => 20, 'expected' => 60, 'high' => 150], 'months' => ['low' => 2, 'high' => 4, 'basis' => 'An assumption.']];

        $t = $repo->track($project, $idea, ['mold on pepper ✓', 'moldy blossoms'], $prediction, 'https://shop.test/blog/mold', '2026-08-01', ArticleTracking::MANUAL, $owner['user']->id, '2026-08-02 10:00:00');
        self::assertSame(['mold on pepper ✓', ['mold on pepper ✓', 'moldy blossoms'], ArticleIdea::UPDATE, 'medium', $prediction, '2026-08-01', null], [$t->mainKeyword, $t->keywords, $t->kind, $t->level, $t->prediction, $t->publishedOn, $t->result]);
        $result = ['weeks' => [['week' => '2026-09-14', 'clicks' => 12, 'impressions' => 90, 'position' => 8.6]], 'top10_week' => '2026-09-14', 'best_position' => 8.6, 'clicks_28d' => 12];
        $repo->saveResult($t->id, $result, '2026-10-18 06:53:00');
        $checked = $repo->find($project, $t->publicId);
        self::assertSame([$result, '2026-10-18 06:53:00'], [$checked?->result, $checked?->checkedAt]);

        // Marking again replaces address, date and source, keeps the snapshot and starts the measurement over.
        $again = $repo->track($project, $idea, ['other'], ['traffic' => ['low' => 0, 'expected' => 0, 'high' => 0], 'months' => ['low' => 9, 'high' => 9, 'basis' => '']], 'https://shop.test/blog/mold-guide', '2026-09-01', ArticleTracking::BRIEF, null, '2026-10-18 11:00:00');
        self::assertSame([$t->publicId, 'https://shop.test/blog/mold-guide', '2026-09-01', ArticleTracking::BRIEF, null, ['mold on pepper ✓', 'moldy blossoms'], $prediction], [$again->publicId, $again->url, $again->publishedOn, $again->source, $again->result, $again->keywords, $again->prediction]);
        self::assertSame([$t->id], array_map(static fn(ArticleTracking $x): int => $x->id, $repo->due(10)));

        $foreign = new Project($project->id, $project->publicId, $workspace + 1, $project->name, $project->primaryDomain, $project->timezone, $project->createdAt, $project->updatedAt);
        self::assertNull($repo->find($foreign, $t->publicId), 'Never through another workspace.');
        self::assertSame([], $repo->forProject($foreign, 10));
        $repo->delete($foreign, $t->id);
        self::assertCount(1, $repo->forProject($project, 10));
        $repo->delete($project, $t->id);
        self::assertSame([], $repo->forProject($project, 10));
    }
}
