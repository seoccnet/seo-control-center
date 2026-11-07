<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Comparison\Analysis;
use SeoControlCenter\Domain\Comparison\AnalysisRepository;
use SeoControlCenter\Domain\Comparison\PageStructure;
use SeoControlCenter\Domain\Projects\ProjectRepository;

/**
 * "Why they outrank you" analyses against MySQL/MariaDB (ADR-038, D1).
 */
final class AnalysisPersistenceTest extends FullStackTestCase
{
    public function testQueueRunCompleteAndLatestPerTopic(): void
    {
        $owner = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace'];
        $id = '0192f0a0-0000-7000-8000-000000000201';
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$id, $workspace->id]);
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($workspace->id, $id);
        self::assertNotNull($project);
        $repo = $this->app->make(AnalysisRepository::class);
        $topic = '0192f0a0-0000-7000-8000-0000000000e1';
        $other = '0192f0a0-0000-7000-8000-0000000000e2';

        $first = $repo->queue($project, $topic, 'sunflower guide', 'sunflower guide', ['sunflower guide', 'sunflower growing guide'], 2300.0, Analysis::STORED, 2840, 'en', $owner['user']->id, '2026-10-16 08:00:00');
        self::assertSame(Analysis::QUEUED, $repo->find($project, $first)?->status);
        self::assertTrue($repo->start($first, '2026-10-16 08:01:00'));
        self::assertFalse($repo->start($first, '2026-10-16 08:01:05'), 'Only one worker starts an analysis.');
        $page = new PageStructure('https://a.test/grow', PageStructure::OK, title: 'Grow ü', outline: [['level' => 2, 'text' => 'Soil']], schemaTypes: ['Article'], wordCount: 900, elements: ['byline' => true], hash: str_repeat('a', 64));
        $repo->complete($first, [['position' => 1, 'url' => 'https://a.test/grow', 'domain' => 'a.test', 'title' => 'Grow', 'type' => 'article', 'confidence' => 'high', 'signals' => ['Article schema'], 'own' => false, 'fetched' => true]], 'https://shop.test/guide', 4, ['https://a.test/grow' => $page->toArray()], ['diagnosis' => ['bottleneck' => 'content', 'actions' => [['type' => 'add_section', 'title' => 'Cover', 'what' => 'Add', 'evidence' => ['https://a.test/grow'], 'effort' => 'medium', 'weight' => 0.8]]]], null, '2026-10-16 08:03:00');
        $done = $repo->find($project, $first);
        self::assertNotNull($done);
        self::assertSame([Analysis::DONE, 'https://shop.test/guide', 4, 'content', 'Grow ü', 900], [$done->status, $done->ownUrl, $done->ownPosition, $done->bottleneck(), $done->pages['https://a.test/grow']->title, $done->pages['https://a.test/grow']->wordCount]);
        self::assertSame('add_section', $done->actions()[0]['type']);
        self::assertSame(['sunflower guide', 'sunflower growing guide'], $done->keywords);

        $second = $repo->queue($project, $topic, 'sunflower guide', 'sunflower guide', ['sunflower guide'], 2300.0, Analysis::PAID, 2840, 'en', $owner['user']->id, '2026-10-16 09:00:00');
        self::assertSame($second, $repo->latestForCluster($project, $topic)?->publicId);
        $repo->start($second, '2026-10-16 09:00:30');
        $repo->fail($second, 'DataForSEO budget exhausted.', '2026-10-16 09:01:00');
        $failed = $repo->byId($second);
        self::assertSame([Analysis::FAILED, 'DataForSEO budget exhausted.'], [$failed?->status, $failed?->error]);

        $third = $repo->queue($project, $other, 'trail shoes', 'trail shoes', ['trail shoes'], 100.0, Analysis::STORED, 2840, 'en', $owner['user']->id, '2026-10-16 10:00:00');
        $repo->start($third, '2026-10-16 10:00:10');
        $repo->complete($third, [], null, null, [], [], 0.002, '2026-10-16 10:01:00');
        $latest = $repo->latestDone($project->id, '2026-10-15 00:00:00');
        self::assertEqualsCanonicalizing([$first, $third], array_map(static fn(Analysis $a): string => $a->publicId, $latest), 'Newest finished analysis per topic; failed runs are ignored.');
        self::assertSame([], $repo->latestDone($project->id, '2026-10-17 00:00:00'));

        $foreign = new \SeoControlCenter\Domain\Projects\Project($project->id, $project->publicId, $workspace->id + 1, $project->name, $project->primaryDomain, $project->timezone, $project->createdAt, $project->updatedAt);
        self::assertNull($repo->find($foreign, $first));
        self::assertNull($repo->latestForCluster($foreign, $topic));
        $this->db->execute('UPDATE projects SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$project->id]);
        self::assertNull($repo->byId($first), 'A deleted project is not analyzed.');
    }
}
