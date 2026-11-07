<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Backlinks\BacklinkProfile;
use SeoControlCenter\Modules\Backlinks\BacklinkHistory;
use SeoControlCenter\Modules\Research\ResearchRepository;

/**
 * Monthly backlink settings, link profile history and the bl_* lookups against MySQL/MariaDB.
 */
final class BacklinkHistoryPersistenceTest extends FullStackTestCase
{
    public function testMonthlySettingHistoryAndLookups(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($acme['workspace']);
        $ws = $acme['workspace']->id;
        $this->db->execute(
            "INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at)
             VALUES ('0192f0a0-0000-7000-8000-000000000081', ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$ws],
        );
        $project = $this->db->lastInsertId();
        $history = $this->app->make(BacklinkHistory::class);

        self::assertFalse($history->isMonthly($project));
        self::assertSame([], $history->dueMonthly('2026-10-01'));
        $history->setMonthly($project, $ws, true);
        self::assertTrue($history->isMonthly($project));
        self::assertSame([['projectId' => $project, 'workspaceId' => $ws, 'projectPublicId' => '0192f0a0-0000-7000-8000-000000000081']], $history->dueMonthly('2026-10-01'));

        $history->record($project, $ws, '2026-09-03', [new BacklinkProfile('shop.test', 30, 900, 120, 30, 4), new BacklinkProfile('rival.test', 55, 25000, 1800, null, 300)]);
        $history->record($project, $ws, '2026-10-03', [new BacklinkProfile('shop.test', 31, 950, 125, 30, 4)]);
        $history->record($project, $ws, '2026-10-03', [new BacklinkProfile('shop.test', 33, 1000, 135, 30, null)]);

        self::assertSame([], $history->dueMonthly('2026-10-01'), 'checked on 2026-10-03');
        self::assertCount(1, $history->dueMonthly('2026-10-03'));
        self::assertTrue($history->isMonthly($project), 'recording keeps the setting');
        $rows = $history->history($project, 12);
        self::assertSame(['2026-10-03', '2026-09-03'], array_column($rows, 'date'));
        self::assertSame(['shop.test'], array_keys($rows[0]['profiles']), 'a re-check of the same day replaces it');
        self::assertSame([33, 1000, 135, null], [$rows[0]['profiles']['shop.test']->rank, $rows[0]['profiles']['shop.test']->backlinks, $rows[0]['profiles']['shop.test']->referringDomains, $rows[0]['profiles']['shop.test']->spamScore]);
        self::assertSame(255, $rows[1]['profiles']['rival.test']->spamScore, 'clamped to the column');
        self::assertCount(1, $history->history($project, 1));

        $history->setMonthly($project, $ws, false);
        self::assertSame([], $history->dueMonthly('2026-12-01'));

        $results = $this->app->make(ResearchRepository::class);
        foreach (['bl_profiles', 'bl_domains', 'bl_new', 'bl_lost', 'bl_anchors', 'bl_gap'] as $kind) {
            $results->queue($project, $ws, $kind, '', ['domain' => 'shop.test'], null);
            $results->complete($project, $kind, '', [['d' => 'blog.test', 'r' => 40]]);
            self::assertSame([['d' => 'blog.test', 'r' => 40]], $results->find($project, $kind)?->items, $kind);
        }
    }
}
