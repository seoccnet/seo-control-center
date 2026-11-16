<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Modules\Competitors\CompetitorWatchRepository;

/** Competitor sitemap reads (1.6.0) on the real engine. */
final class CompetitorWatchPersistenceTest extends FullStackTestCase
{
    public function testBaselineNewRemovedAndDue(): void
    {
        $owner = $this->createUser('watch@acme.test', 'Watch');
        self::assertNotNull($owner['workspace']);
        $ws = $owner['workspace']->id;
        $publicId = $this->createProject($this->browserFor('watch@acme.test'), 'Watch', 'acme.test');
        $project = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$publicId]);
        $rival = $this->app->make(CompetitorRepository::class)->add($project, 'rival.test', Competitor::MANUAL, $owner['user']->id) ?? self::fail('Competitor not added.');
        $repo = $this->app->make(CompetitorWatchRepository::class);

        self::assertSame([], $repo->due('2026-11-16', 10), 'Projects that do not watch are not due.');
        $repo->setEnabled($project, $ws, true);
        self::assertTrue($repo->enabled($project));
        self::assertSame([['competitorId' => $rival->id, 'projectId' => $project, 'workspaceId' => $ws, 'domain' => 'rival.test']], $repo->due('2026-11-16', 10));

        $base = array_map(static fn(int $i): string => 'https://rival.test/p' . $i, range(1, 1200));
        self::assertSame(['added' => 0, 'removed' => 0], $repo->record($rival->id, $project, $base, '2026-11-16', false));
        self::assertSame([], $repo->due('2026-11-16', 10));
        self::assertSame(1200, $repo->states($project)[$rival->id]['urls'] ?? null);

        $next = [...array_slice($base, 2), 'https://rival.test/new', 'https://rival.test/new'];
        self::assertSame(['added' => 1, 'removed' => 2], $repo->record($rival->id, $project, $next, '2026-11-17', false));
        $changes = $repo->changes($project, '2026-11-01', 10);
        self::assertSame(['new', 'removed', 'removed'], array_column($changes, 'kind'));
        self::assertSame('https://rival.test/new', $changes[0]['url']);

        // A page that comes back is no longer removed; a capped read removes nothing.
        self::assertSame(['added' => 0, 'removed' => 0], $repo->record($rival->id, $project, [...$next, 'https://rival.test/p1'], '2026-11-18', true));
        self::assertCount(2, $repo->changes($project, '2026-11-01', 10));
        $repo->fail($rival->id, $project, '2026-11-19', str_repeat('e', 300));
        $state = $repo->states($project)[$rival->id] ?? self::fail('State lost.');
        self::assertSame(255, mb_strlen((string) $state['error']));
        self::assertSame('2026-11-16', $state['baseline_on']);
    }
}
