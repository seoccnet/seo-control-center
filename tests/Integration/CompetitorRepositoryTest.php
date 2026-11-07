<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Keywords\KeywordMetrics;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;

/**
 * Competitors, their positions, backfill, candidates and comparison against MySQL/MariaDB.
 */
final class CompetitorRepositoryTest extends FullStackTestCase
{
    public function testCompetitorLifecycleBackfillAggregatesAndComparison(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($acme['workspace']);
        $ws = $acme['workspace']->id;
        $project = $this->project($ws, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f91');
        $other = $this->project($ws, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f92');
        $keywords = $this->app->make(KeywordRepository::class);
        $rankings = $this->app->make(RankingRepository::class);
        $repo = $this->app->make(CompetitorRepository::class);
        $keywords->add($project, ['red shoes', 'blue shoes'], KeywordLocale::default(), $acme['user']->id);
        $keywords->storeMetrics($ws, KeywordLocale::default(), [new KeywordMetrics('red shoes', 1000, null, null, null)], new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        [$red, $blue] = array_map('intval', array_column($this->db->fetchAll('SELECT id FROM project_keywords WHERE project_id = ? ORDER BY id', [$project]), 'id'));
        /** @param list<string> $domains */
        $top = static fn(array $domains): array => array_map(static fn(int $i, string $d): array => ['position' => $i + 1, 'domain' => $d, 'url' => 'https://' . $d . '/'], array_keys($domains), $domains);
        $rankings->storeSnapshot(new RankSnapshot($red, $project, SerpDevice::Desktop, '2026-09-26', 4, 4, null, [], $top(['www.rival.test', 'big.test', 'x.test', 'acme.test'])));
        $rankings->storeSnapshot(new RankSnapshot($blue, $project, SerpDevice::Desktop, '2026-09-26', null, null, null, [], $top(['big.test', 'blog.rival.test'])));

        $candidates = $repo->candidates($project, SerpDevice::Desktop, '2026-09-26', 'acme.test', 10);
        self::assertSame(['big.test', 'rival.test', 'blog.rival.test', 'x.test'], array_map(static fn($c): string => $c->domain, $candidates));
        self::assertSame(2, $candidates[0]->keywords);
        self::assertSame(1.5, $candidates[0]->averagePosition);

        $rival = $repo->add($project, 'rival.test', Competitor::DISCOVERED, $acme['user']->id);
        self::assertNotNull($rival);
        self::assertNull($repo->add($project, 'rival.test', Competitor::MANUAL, $acme['user']->id), 'Unique per project.');
        self::assertNotNull($repo->add($other, 'rival.test', Competitor::MANUAL, $acme['user']->id), 'Other projects can track the same domain.');
        self::assertSame(['big.test', 'x.test'], array_map(static fn($c): string => $c->domain, $repo->candidates($project, SerpDevice::Desktop, '2026-09-26', 'acme.test', 10)), 'Existing competitors and their subdomains are no candidates.');

        self::assertSame(['2026-09-26'], $repo->backfill($rival, SerpDevice::Desktop, ['2026-09-26', '2026-09-19']));
        $repo->aggregate($project, $ws, SerpDevice::Desktop, '2026-09-26');
        $day = $repo->days($project, SerpDevice::Desktop, '2026-09-26')[$rival->id] ?? null;
        self::assertNotNull($day);
        self::assertSame(2, $day->ranked);
        self::assertSame(2, $day->top3);
        self::assertSame(1.5, $day->averagePosition);
        self::assertSame([], $repo->days($other, SerpDevice::Desktop, '2026-09-26'));

        // A real check result replaces the backfilled estimate; a later backfill does not overwrite it.
        $repo->recordPositions($blue, SerpDevice::Desktop, '2026-09-26', [['competitor_id' => $rival->id, 'position' => 7, 'url' => 'https://blog.rival.test/blue']]);
        $repo->backfill($rival, SerpDevice::Desktop, ['2026-09-26']);
        $comparison = $repo->compare($project, $ws, $rival->id, SerpDevice::Desktop, '2026-09-26');
        self::assertSame(['red shoes', 'blue shoes'], array_map(static fn($c): string => $c->keyword, $comparison), 'By search volume.');
        self::assertSame([4, null], array_map(static fn($c): ?int => $c->own, $comparison));
        self::assertSame([1, 7], array_map(static fn($c): ?int => $c->theirs, $comparison));
        self::assertTrue($comparison[1]->isGap());
        self::assertSame(1000, $comparison[0]->searchVolume);
        self::assertSame([], $repo->compare($other, $ws, $rival->id, SerpDevice::Desktop, '2026-09-26'), 'A competitor of another project cannot be compared.');

        self::assertSame($rival->id, $repo->find($project, $rival->publicId)?->id);
        self::assertNull($repo->find($other, $rival->publicId));
        self::assertFalse($repo->remove($other, $rival->publicId));
        self::assertTrue($repo->remove($project, $rival->publicId));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM competitor_rankings WHERE competitor_id = ?', [$rival->id]), 'Positions are deleted with the competitor.');
    }

    private function project(int $workspaceId, string $publicId): int
    {
        $this->db->execute(
            "INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at)
             VALUES (?, ?, 'Shop', 'acme.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$publicId, $workspaceId],
        );

        return $this->db->lastInsertId();
    }
}
