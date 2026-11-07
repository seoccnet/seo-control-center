<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityOutcome;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\OutcomeMetrics;
use SeoControlCenter\Domain\Opportunities\OutcomeRepository;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Modules\PageMonitoring\PageMonitoringRepository;
use SeoControlCenter\Modules\PageMonitoring\PageSnapshot;

/** ADR-039, E2: watch limits, before/after checks and verified outcomes in MariaDB/MySQL. */
final class VerifiedOutcomesPersistenceTest extends FullStackTestCase
{
    public function testWatchLimitAfterChecksAndPageChangesForOneUrl(): void
    {
        $owner = $this->createUser('limits@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace']->id;
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", ['0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fb1', $workspace]);
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($workspace, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fb1');
        self::assertNotNull($project);
        $data = $this->app->make(PageMonitoringRepository::class);

        self::assertSame(50, $data->limit($workspace));
        $data->setLimit($workspace, 2);
        self::assertSame(2, $data->limit($workspace));
        $data->setLimit($workspace, 900);
        self::assertSame(200, $data->limit($workspace), 'The stored limit is clamped.');
        $data->setLimit($workspace, 2);
        self::assertTrue($data->watch($project, 'https://shop.test/a', 2));
        self::assertTrue($data->watch($project, 'https://shop.test/b', 2, 'change_note'));
        self::assertFalse($data->watch($project, 'https://shop.test/a', 2));
        try {
            $data->watch($project, 'https://shop.test/c', 2);
            self::fail('The third page is over the limit.');
        } catch (HttpException $e) {
            self::assertSame(409, $e->statusCode());
        }
        self::assertSame(['checked_at' => null, 'reason' => 'change_note', 'after_due_at' => null], $data->watched($project, 'https://shop.test/b'));
        self::assertNull($data->watched($project, 'https://shop.test/c'));

        // Both pages were checked today, so only an "after" check makes one due again.
        foreach (['https://shop.test/a', 'https://shop.test/b'] as $url) {
            $data->attempted($project->id, $url, $this->generation($data, $project->id, $url), '2026-10-10 05:00:00', null);
        }
        self::assertSame([], $data->due('2026-10-03 06:00:00', '2026-10-10 06:00:00'));
        $data->afterCheck($project, 'https://shop.test/b', '2026-10-13 05:00:00');
        self::assertSame([], $data->due('2026-10-06 06:00:00', '2026-10-12 06:00:00'));
        self::assertSame(['https://shop.test/b'], array_column($data->due('2026-10-06 06:00:00', '2026-10-13 06:00:00'), 'url'));
        $generation = $this->generation($data, $project->id, 'https://shop.test/b');
        $data->attempted($project->id, 'https://shop.test/b', $generation, '2026-10-13 06:00:00', null);
        $watch = $data->watched($project, 'https://shop.test/b');
        self::assertNotNull($watch);
        self::assertNull($watch['after_due_at'], 'A finished after check is cleared.');

        $data->save($project->id, 'https://shop.test/b', $generation, new PageSnapshot(['status' => 200, 'html' => true, 'title' => 'Old']), '2026-10-13 06:00:00', Uuid::v7());
        $data->save($project->id, 'https://shop.test/b', $generation, new PageSnapshot(['status' => 200, 'html' => true, 'title' => 'New']), '2026-10-14 06:00:00', Uuid::v7());
        $changes = $data->changesFor($project->id, 'http://SHOP.test/b/', '2026-10-01 00:00:00', '2026-10-31 23:59:59');
        self::assertCount(1, $changes, 'The same page whatever the scheme, case of the host or trailing slash.');
        self::assertSame(['title'], $changes[0]['fields']);
        self::assertSame([], $data->changesFor($project->id, 'https://shop.test/a', '2026-10-01 00:00:00', '2026-10-31 23:59:59'));
        self::assertSame([], $data->changesFor($project->id, 'https://shop.test/b', '2026-10-15 00:00:00', '2026-10-31 23:59:59'));
        self::assertSame([], $data->changesFor($project->id + 1000, 'https://shop.test/b', '2026-10-01 00:00:00', '2026-10-31 23:59:59'));
    }

    public function testImplementationOverlapsAndCalibrationWithoutNotDoneOutcomes(): void
    {
        $owner = $this->createUser('verified@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace']->id;
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", ['0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fb2', $workspace]);
        $project = $this->db->lastInsertId();
        $opportunities = $this->app->make(OpportunityRepository::class);
        $opportunities->sync($project, [
            new Opportunity(OpportunityType::QuickWin, 'page:shop.test/a', 'A', 'A', [['label' => 'Page', 'value' => 'https://shop.test/a']], 50, 80, 1, 100),
            new Opportunity(OpportunityType::QuickWin, 'page:shop.test/b', 'B', 'B', [['label' => 'Page', 'value' => 'https://shop.test/b']], 50, 80, 1, 100),
        ]);
        [$a, $b] = $opportunities->catalog($project)['rows'];
        $repository = $this->app->make(OutcomeRepository::class);
        $baseline = new OutcomeMetrics(100, 1000, 8);
        $repository->record($project, $workspace, $a, '2026-08-01', $baseline, null, 'finding', 'https://shop.test/a');
        $repository->record($project, $workspace, $b, '2026-08-02', $baseline, null, 'finding', 'https://shop.test/b');

        $unverified = $repository->unverified('2026-07-01', 10);
        self::assertSame([28, 28], array_map(static fn(OpportunityOutcome $r): int => $r->checkpoint, $unverified), 'One row per completion.');
        self::assertSame(['https://shop.test/a', 'https://shop.test/b'], array_map(static fn(OpportunityOutcome $r): ?string => $r->pageUrl, $unverified));
        self::assertSame([], $repository->unverified('2026-09-01', 10));
        self::assertCount(1, $repository->completions($project, '2026-08-02', '2026-08-30'));
        self::assertSame([], $repository->completions($project + 1000, '2026-08-01', '2026-08-30'));

        $first = $unverified[0];
        self::assertNull($repository->find($project, $workspace + 1000, $first->publicId), 'Never found through another workspace.');
        $repository->setImplementation($first, OpportunityOutcome::VERIFIED, 'page_change:x', '2026-08-05 04:00:00');
        foreach (array_filter($repository->recent($project), static fn(OpportunityOutcome $r): bool => $r->opportunityId === $first->opportunityId) as $row) {
            self::assertSame(OpportunityOutcome::VERIFIED, $row->implementation);
            self::assertSame('page_change:x', $row->implementationSource);
        }
        self::assertCount(1, $repository->unverified('2026-07-01', 10));
        $overlaps = [['kind' => 'note', 'label' => 'Site-wide change: Theme — “new”', 'date' => '2026-08-10', 'ref' => 'n1']];
        $repository->setOverlaps($first, $overlaps);
        self::assertSame($overlaps, $repository->find($project, $workspace, $first->publicId)?->overlaps);

        // Day 56 measured for both; B was not done and leaves the calibration.
        foreach ($repository->recent($project) as $row) {
            if ($row->checkpoint === 56) {
                $repository->measured($row, $baseline, new OutcomeMetrics($row->opportunityId === $a->publicId ? 180 : 500, 1200, 6), '2026-10-01 00:00:00');
            }
        }
        self::assertSame(2, $repository->factors($project, '2026-01-01')['quick_win']['samples']);
        $second = $repository->find($project, $workspace, $unverified[1]->publicId);
        self::assertNotNull($second);
        $repository->setImplementation($second, OpportunityOutcome::NOT_IMPLEMENTED, 'user', '2026-10-02 00:00:00');
        self::assertSame(1, $repository->factors($project, '2026-01-01')['quick_win']['samples']);
    }

    public function testBackfillFillsOlderOutcomesFromTasksAndEvidence(): void
    {
        $owner = $this->createUser('backfill@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace']->id;
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", ['0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fb3', $workspace]);
        $project = $this->db->lastInsertId();
        $opportunities = $this->app->make(OpportunityRepository::class);
        $opportunities->sync($project, [
            new Opportunity(OpportunityType::QuickWin, 'query:a', 'A', 'A', [['label' => 'Value page', 'value' => 'https://shop.test/a/ (12 clicks)']], 50, 80, 1, 100),
            new Opportunity(OpportunityType::QuickWin, 'query:b', 'B', 'B', [['label' => 'Page', 'value' => 'https://shop.test/b']], 50, 80, 1, 100),
            new Opportunity(OpportunityType::QuickWin, 'query:c', 'C', 'C', [['label' => 'Query', 'value' => 'c']], 50, 80, 1, 100),
        ]);
        $rows = [];
        foreach ($opportunities->catalog($project)['rows'] as $row) {
            $rows[$row->subject] = $row;
        }
        $repository = $this->app->make(OutcomeRepository::class);
        foreach ($rows as $row) {
            $repository->record($project, $workspace, $row, '2026-10-12', null, null, 'finding');
        }
        $this->db->execute("INSERT INTO opportunity_tasks (workspace_id, project_id, opportunity_id, status, notes, url, updated_at) SELECT ?, ?, id, 'done', '', 'https://shop.test/b-task', UTC_TIMESTAMP() FROM opportunities WHERE project_id = ? AND public_id = ?", [$workspace, $project, $project, $rows['query:b']->publicId]);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM opportunity_outcomes WHERE project_id = ? AND page_url IS NOT NULL', [$project]));

        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_17_000100_backfill_outcome_pages.php';
        $migration->up($this->db);
        $migration->up($this->db);
        $pages = [];
        foreach ($repository->recent($project) as $outcome) {
            $pages[$outcome->opportunityId . ':' . $outcome->checkpoint] = $outcome->pageUrl;
        }
        self::assertSame('https://shop.test/a/', $pages[$rows['query:a']->publicId . ':28']);
        self::assertSame('https://shop.test/a/', $pages[$rows['query:a']->publicId . ':56']);
        self::assertSame('https://shop.test/b-task', $pages[$rows['query:b']->publicId . ':28'], 'The team task page wins.');
        self::assertNull($pages[$rows['query:c']->publicId . ':28']);
        self::assertCount(2, $repository->unverified('2026-10-01', 10));
    }

    private function generation(PageMonitoringRepository $data, int $projectId, string $url): string
    {
        $row = $data->find($projectId, $url);
        self::assertNotNull($row);
        self::assertIsString($row['generation'] ?? null);

        return $row['generation'];
    }
}
