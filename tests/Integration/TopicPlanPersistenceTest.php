<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\ArticleIdeas\TopicPlan;
use SeoControlCenter\Domain\ArticleIdeas\TopicPlanRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;

/** ADR-041, G2: topic plans on MySQL, one per pillar, bound to one project. */
final class TopicPlanPersistenceTest extends FullStackTestCase
{
    public function testPlansMembersLinksAndBacklinks(): void
    {
        $owner = $this->createUser('plans@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace']->id;
        $id = '0192f0a0-0000-7000-8000-000000000601';
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$id, $workspace]);
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($workspace, $id);
        self::assertNotNull($project);
        $repo = $this->app->make(TopicPlanRepository::class);
        $pillar = str_repeat('a', 64);
        $members = [
            ['type' => TopicPlan::IDEA, 'key' => str_repeat('b', 64), 'label' => 'brandywine vs roma', 'volume' => 900, 'kept' => true, 'url' => null],
            ['type' => TopicPlan::SEARCH, 'key' => 'brandywine seeds ✓', 'label' => 'brandywine seeds ✓', 'volume' => 450, 'kept' => false, 'url' => null],
            ['type' => TopicPlan::PAGE, 'key' => 'https://shop.test/seeds/brandywine', 'label' => '/seeds/brandywine', 'volume' => 400, 'kept' => true, 'url' => 'https://shop.test/seeds/brandywine'],
        ];

        $plan = $repo->create($project, $pillar, 'brandywine cultivar', $members, $owner['user']->id, '2026-10-18 10:00:00');
        self::assertSame([$pillar, 'brandywine cultivar', $members], [$plan->pillarKey, $plan->pillarKeyword, $plan->members]);
        self::assertSame($plan->id, $repo->create($project, $pillar, 'brandywine cultivar', [], null, '2026-10-18 10:01:00')->id, 'One plan per pillar; the first stays.');
        self::assertSame($members, $repo->forPillar($project, $pillar)?->members);
        self::assertSame($plan->id, $repo->find($project, $plan->publicId)?->id);

        $members[1]['kept'] = true;
        $repo->saveMembers($plan->id, $members, '2026-10-18 10:02:00');
        self::assertSame(['brandywine seeds ✓'], array_column($repo->byId($plan->id)?->kept(TopicPlan::SEARCH) ?? [], 'key'));

        self::assertTrue($repo->queueLinks($plan->id, '2026-10-18 10:03:00'));
        self::assertFalse($repo->queueLinks($plan->id, '2026-10-18 10:03:01'), 'Already queued.');
        $links = ['internal' => [['from' => 'pillar', 'to' => str_repeat('b', 64), 'anchor' => 'brandywine vs roma']], 'pages' => [['source_url' => 'https://shop.test/blog/cultivars', 'to' => 'pillar', 'anchor' => 'Brandywine', 'paragraph' => 'Our Brandywine…']], 'audit' => '2026-10-17 09:00:00'];
        $repo->saveLinks($plan->id, $links, '2026-10-18 10:04:00');
        $saved = $repo->byId($plan->id);
        self::assertSame([$links, TopicPlan::DONE, '2026-10-18 10:04:00'], [$saved?->links, $saved?->linksStatus, $saved?->linksBuiltAt]);
        self::assertTrue($repo->queueLinks($plan->id, '2026-10-18 10:05:00'));

        self::assertTrue($repo->queueBacklinks($plan->id, 0.048, '2026-10-18 10:06:00'));
        self::assertFalse($repo->queueBacklinks($plan->id, 0.048, '2026-10-18 10:06:01'));
        $pages = [['url' => 'https://rival.test/blog/brandywine', 'referring_domains' => 48, 'backlinks' => 420, 'rank' => 21], ['url' => 'https://other.test/g', 'referring_domains' => null, 'backlinks' => null, 'rank' => null]];
        $repo->saveBacklinks($plan->id, $pages, '2026-10-18 10:07:00');
        $repo->failBacklinks($plan->id, 'Late failure.', '2026-10-18 10:08:00');
        $checked = $repo->byId($plan->id);
        self::assertSame([TopicPlan::DONE, $pages, 0.048, null, 48], [$checked?->backlinksStatus, $checked?->backlinks, $checked?->backlinksCost, $checked?->backlinksError, $checked?->referringDomainsToMatch()], 'A finished check is not overwritten.');

        $foreign = new Project($project->id, $project->publicId, $workspace + 1, $project->name, $project->primaryDomain, $project->timezone, $project->createdAt, $project->updatedAt);
        self::assertNull($repo->find($foreign, $plan->publicId), 'Never through another workspace.');
        self::assertSame([], $repo->forProject($foreign, 10));
        $repo->delete($foreign, $plan->id);
        self::assertNotNull($repo->byId($plan->id));
        self::assertCount(1, $repo->forProject($project, 10));
        $repo->delete($project, $plan->id);
        self::assertNull($repo->byId($plan->id));
    }
}
