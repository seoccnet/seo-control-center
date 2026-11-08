<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Keywords\KeywordMetrics;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Keywords\KeywordSyncState;

/**
 * Project keywords and workspace-scoped keyword metrics against MySQL/MariaDB.
 */
final class KeywordRepositoryTest extends FullStackTestCase
{
    public function testKeywordsMetricsAndStalenessAreScopedCorrectly(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        $rival = $this->createUser('owner@rival.test', 'Rival');
        self::assertNotNull($acme['workspace']);
        self::assertNotNull($rival['workspace']);
        $ws = $acme['workspace']->id;
        $project = $this->project($ws, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f71');
        $rivalProject = $this->project($rival['workspace']->id, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f72');
        $repo = $this->app->make(KeywordRepository::class);
        $us = new KeywordLocale(2840, 'en');
        $de = new KeywordLocale(2276, 'de');

        self::assertSame(2, $repo->add($project, ['red shoes', 'blue shoes'], $us, $acme['user']->id));
        self::assertSame(2, $repo->add($project, ['red shoes', 'rote schuhe'], $de, $acme['user']->id), 'The same keyword in another market is a separate keyword.');
        self::assertSame(0, $repo->add($project, ['red shoes'], $us, $acme['user']->id), 'Duplicates are skipped.');
        $repo->add($rivalProject, ['red shoes'], $us, $rival['user']->id);
        self::assertSame(4, $repo->count($project));
        self::assertTrue($repo->lastLocale($project)?->equals($de));
        self::assertSame(['2840:en', '2276:de'], array_map(static fn(KeywordLocale $l): string => $l->key(), $repo->marketsByCount($project, 20)), 'A tie keeps the market added first.');
        self::assertSame(['2840:en'], array_map(static fn(KeywordLocale $l): string => $l->key(), $repo->marketsByCount($project, 1)));
        self::assertSame(['2840:en'], array_map(static fn(KeywordLocale $l): string => $l->key(), $repo->marketsByCount($rivalProject, 20)), 'Only keywords of the project itself count.');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $pending = $repo->needingMetrics($project, $ws, $now->modify('-30 days'), 100);
        self::assertCount(2, $pending);
        self::assertSame(['red shoes', 'blue shoes'], $pending[1]['keywords']);

        $repo->storeMetrics($ws, $us, [
            new KeywordMetrics('Red Shoes', 12100, 1.37, 88, 'high', [['year' => 2026, 'month' => 8, 'volume' => 12100]]),
            KeywordMetrics::empty('blue shoes'),
        ], $now);
        $repo->storeMetrics($ws, $us, [new KeywordMetrics('red shoes', 14800, 1.5, 90, 'high')], $now); // replaces

        $page = $repo->page($project, $ws, 10, 0);
        self::assertSame('red shoes', $page[0]->keyword);
        self::assertSame(14800, $page[0]->metrics?->searchVolume);
        self::assertSame('blue shoes', $page[1]->keyword);
        self::assertNull($page[1]->metrics?->searchVolume);
        self::assertNull($page[2]->metrics, 'German keywords have no metrics yet.');

        // Metrics belong to the workspace that bought them.
        self::assertNull($repo->page($rivalProject, $rival['workspace']->id, 10, 0)[0]->metrics);

        $pending = $repo->needingMetrics($project, $ws, $now->modify('-30 days'), 100);
        self::assertCount(1, $pending);
        self::assertTrue($pending[0]['locale']->equals($de));
        self::assertCount(4, array_merge(...array_column($repo->needingMetrics($project, $ws, $now->modify('+1 minute'), 100), 'keywords')), 'Everything is stale after the window.');

        // Wrong workspace or deleted project: nothing to fetch, so nothing is paid for.
        self::assertSame([], $repo->needingMetrics($project, $rival['workspace']->id, $now, 100));
        $this->db->execute('UPDATE projects SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$project]);
        self::assertSame([], $repo->needingMetrics($project, $ws, $now->modify('+1 minute'), 100));

        self::assertTrue($repo->remove($project, $page[0]->publicId));
        self::assertFalse($repo->remove($rivalProject, $page[1]->publicId), 'Removal is scoped to the project.');
        self::assertSame(3, $repo->count($project));
    }

    public function testSyncStateKeepsTimestamps(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($acme['workspace']);
        $project = $this->project($acme['workspace']->id, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f73');
        $repo = $this->app->make(KeywordRepository::class);

        self::assertNull($repo->syncState($project));
        $repo->setSyncState($project, KeywordSyncState::PENDING);
        $repo->setSyncState($project, KeywordSyncState::RUNNING);
        $repo->setSyncState($project, KeywordSyncState::OK);
        $repo->setSyncState($project, KeywordSyncState::ERROR, 'Budget used up');

        $state = $repo->syncState($project);
        self::assertSame(KeywordSyncState::ERROR, $state?->status);
        self::assertSame('Budget used up', $state->lastError);
        self::assertNotNull($state->requestedAt);
        self::assertNotNull($state->lastSuccessAt, 'A later error keeps the last success time.');
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
