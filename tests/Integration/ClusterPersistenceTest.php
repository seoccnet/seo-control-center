<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Clusters\ClusterRepository;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Pages\PageRepository;

/**
 * Saved clusters and the weekly query-to-page read against MySQL/MariaDB
 * (ADR-037, C2).
 */
final class ClusterPersistenceTest extends FullStackTestCase
{
    public function testSyncKeepsManualOwnersRetiresAndScopesToTheWorkspace(): void
    {
        $owner = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace'];
        $id = '0192f0a0-0000-7000-8000-000000000101';
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$id, $workspace->id]);
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($workspace->id, $id);
        self::assertNotNull($project);
        $repo = $this->app->make(ClusterRepository::class);
        $row = static fn(string $publicId, string $label, float $demand, string $status = 'owned'): array => [
            'public_id' => $publicId, 'label' => $label, 'size' => 2, 'demand' => $demand,
            'members' => [['keyword' => $label, 'demand' => $demand], ['keyword' => 'best ' . $label, 'demand' => 1.0]],
            'status' => $status, 'owner_url' => 'https://shop.test/' . str_replace(' ', '-', $label), 'owner_source' => 'search_console', 'inferred_url' => 'https://shop.test/' . str_replace(' ', '-', $label),
            'owner_reason' => 'Gets 90 % of the clicks.', 'candidates' => [['url' => 'https://shop.test/a', 'clicks' => 9, 'impressions' => 90, 'share' => 0.9, 'best_position' => 3.0]],
            'overlap' => [['query' => $label, 'kind' => 'split', 'harmful' => null]], 'overlap_flagged' => 1,
        ];
        $a = '0192f0a0-0000-7000-8000-0000000000c1';
        $b = '0192f0a0-0000-7000-8000-0000000000c2';
        $c = '0192f0a0-0000-7000-8000-0000000000c3';
        $repo->sync($project->id, $workspace->id, [$row($a, 'running shoes ü', 900.0), $row($b, 'wool socks', 50.0, 'contested'), $row($c, 'trail shoes', 300.0)], '2026-10-16 10:00:00');
        self::assertSame(['running shoes ü', 'trail shoes', 'wool socks'], array_map(static fn($cl): string => $cl->label, $repo->forProject($project)));
        $found = $repo->find($project, $b);
        self::assertNotNull($found);
        self::assertSame(['contested', 0.9, 'split', 1], [$found->status, $found->candidates[0]['share'], $found->overlap[0]['kind'], $found->revision]);
        self::assertSame(['wool socks', 'best wool socks'], $repo->previous($project->id)[$b]);

        self::assertTrue($repo->setManualOwner($project, $b, 'https://shop.test/socks', $owner['user']->id, 'Claude', 1, '2026-10-16 10:05:00'));
        self::assertFalse($repo->setManualOwner($project, $b, 'https://shop.test/x', $owner['user']->id, null, 1, '2026-10-16 10:06:00'), 'Stale revision.');
        $manual = $repo->find($project, $b);
        self::assertSame(['owned', 'https://shop.test/socks', 'manual', 'Claude', 2], [$manual?->status, $manual?->ownerUrl, $manual?->ownerSource, $manual?->via, $manual?->revision]);

        $repo->sync($project->id, $workspace->id, [$row($c, 'trail shoes', 350.0)], '2026-10-16 16:00:00');
        self::assertNull($repo->find($project, $a), 'A vanished cluster without a manual owner is deleted.');
        self::assertSame('2026-10-16 16:00:00', $repo->find($project, $b)?->retiredAt, 'A vanished cluster with a manual owner is retired.');
        self::assertSame(350.0, $repo->find($project, $c)?->demand);
        self::assertSame([$c], array_map(static fn($cl): string => $cl->publicId, $repo->forProject($project, false)));
        $repo->sync($project->id, $workspace->id, [$row($b, 'wool socks', 60.0, 'unowned'), $row($c, 'trail shoes', 350.0)], '2026-10-16 22:00:00');
        $back = $repo->find($project, $b);
        self::assertSame([null, 'owned', 'manual'], [$back?->retiredAt, $back?->status, $back?->ownerSource], 'It comes back with its manual owner.');

        $foreign = new \SeoControlCenter\Domain\Projects\Project($project->id, $project->publicId, $workspace->id + 1, $project->name, $project->primaryDomain, $project->timezone, $project->createdAt, $project->updatedAt);
        self::assertNull($repo->find($foreign, $c));
        self::assertSame([], $repo->forProject($foreign));
        self::assertFalse($repo->setManualOwner($foreign, $c, null, $owner['user']->id, null, 1, '2026-10-16 23:00:00'));
    }

    public function testWeeklyQueryPagesGroupsByMondayAndMatchesExactSearches(): void
    {
        $owner = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $id = '0192f0a0-0000-7000-8000-000000000102';
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$id, $owner['workspace']->id]);
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($owner['workspace']->id, $id);
        self::assertNotNull($project);
        foreach (['trail shoes', 'Trail Shoes', 'socks'] as $query) {
            $this->db->execute('INSERT INTO search_queries (project_id, query_hash, query) VALUES (?, UNHEX(?), ?)', [$project->id, hash('sha256', $query), $query]);
        }
        foreach (['https://shop.test/a', 'https://shop.test/b'] as $url) {
            $this->db->execute('INSERT INTO pages (project_id, url_hash, url, first_seen_at) VALUES (?, UNHEX(?), ?, UTC_TIMESTAMP())', [$project->id, hash('sha256', $url), $url]);
        }
        $q = fn(string $query): int => (int) $this->db->fetchValue('SELECT id FROM search_queries WHERE project_id = ? AND query_hash = UNHEX(?)', [$project->id, hash('sha256', $query)]);
        $p = fn(string $url): int => (int) $this->db->fetchValue('SELECT id FROM pages WHERE project_id = ? AND url_hash = UNHEX(?)', [$project->id, hash('sha256', $url)]);
        $insert = fn(string $date, string $query, string $url, int $impressions, float $position) => $this->db->execute('INSERT INTO search_console_query_pages (project_id, date, query_id, page_id, clicks, impressions, position) VALUES (?, ?, ?, ?, 1, ?, ?)', [$project->id, $date, $q($query), $p($url), $impressions, $position]);
        $insert('2026-09-14', 'trail shoes', 'https://shop.test/a', 10, 4.0); // Monday
        $insert('2026-09-20', 'trail shoes', 'https://shop.test/a', 30, 8.0); // Sunday, same week
        $insert('2026-09-21', 'trail shoes', 'https://shop.test/b', 5, 9.0);  // next Monday
        $insert('2026-09-21', 'Trail Shoes', 'https://shop.test/a', 99, 1.0); // another exact search
        $insert('2026-09-21', 'socks', 'https://shop.test/b', 50, 2.0);

        $rows = $this->app->make(PageRepository::class)->weeklyQueryPages($project->id, new DateRange('2026-09-14', '2026-09-27'), ['trail shoes']);
        self::assertSame([
            ['query' => 'trail shoes', 'url' => 'https://shop.test/a', 'week' => '2026-09-14', 'clicks' => 2, 'impressions' => 40, 'position' => 7.0],
            ['query' => 'trail shoes', 'url' => 'https://shop.test/b', 'week' => '2026-09-21', 'clicks' => 1, 'impressions' => 5, 'position' => 9.0],
        ], $rows, 'Impression-weighted position per week; other spellings and searches are excluded.');
        self::assertSame([], $this->app->make(PageRepository::class)->weeklyQueryPages($project->id, new DateRange('2026-09-14', '2026-09-27'), []));
    }
}
