<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Modules\Opportunities\OpportunityAnalyzer;

/**
 * Opportunity sync and workflow against MySQL/MariaDB.
 */
final class OpportunityRepositoryTest extends FullStackTestCase
{
    public function testCoverageProtectsMissingSourcesAndIsProjectScoped(): void
    {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_08_094140_add_opportunity_coverage.php';
        $migration->up($this->db);
        $migration->up($this->db);
        $owner = $this->createUser('coverage@acme.test', 'Coverage');
        self::assertNotNull($owner['workspace']);
        $project = $this->project($owner['workspace']->id, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fb1');
        $other = $this->project($owner['workspace']->id, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fb2');
        $repo = $this->app->make(OpportunityRepository::class);
        $coverage = ['rankings' => ['status' => 'available', 'date' => '2026-10-06']];
        $make = static fn(int $impact): Opportunity => new Opportunity(OpportunityType::QuickWin, 'keyword:stable', 'Title', 'Why', [['label' => 'Required sources', 'value' => 'rankings']], $impact, 70, 1);
        $repo->sync($project, [$make(50)], ['quick_win'], $coverage);
        $id = $repo->list($project, 'open', null, 1, 0)[0]->publicId;
        $missing = ['rankings' => ['status' => 'missing', 'date' => null]];
        self::assertSame(['opened' => 0, 'updated' => 0, 'resolved' => 0], $repo->sync($project, [$make(99)], [], $missing));
        self::assertSame(50, $repo->find($project, $id)?->impact);
        self::assertSame(0, $repo->sync($project, [], [], $missing)['resolved']);
        self::assertSame($missing, $repo->coverage($project));
        self::assertSame([], $repo->coverage($other));
        self::assertNull($repo->find($other, $id));
        self::assertSame(1, $repo->sync($project, [], ['quick_win'], $coverage)['resolved']);
        self::assertSame('resolved', $repo->find($project, $id)?->status);
        $query = new Opportunity(OpportunityType::LowCtr, 'query:sampled', 'Improve CTR', '', [['label' => 'Required sources', 'value' => 'search_console']], 50, 50, 1);
        $complete = ['search_console' => ['status' => 'available', 'date' => '2026-10-06'], 'query_sample' => ['status' => 'available', 'date' => '2026-10-06', 'notice' => 'Analysis covers top 1 of 1 Google queries']];
        $repo->sync($project, [$query], [], $complete);
        $partial = $complete;
        $partial['query_sample'] = ['status' => 'missing', 'date' => '2026-10-06', 'notice' => 'Analysis covers top 500 of 501 Google queries'];
        self::assertSame(0, $repo->sync($project, [], [], $partial)['resolved']);
        self::assertEquals($partial, $repo->coverage($project));
        self::assertSame(1, $repo->sync($project, [], [], $complete)['resolved']);
    }

    public function testSyncLifecycleStatusesAndHistory(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($acme['workspace']);
        $project = $this->project($acme['workspace']->id, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fa1');
        $other = $this->project($acme['workspace']->id, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fa2');
        $repo = $this->app->make(OpportunityRepository::class);
        $o = static fn(string $subject, int $impact, OpportunityType $type = OpportunityType::LowCtr): Opportunity => new Opportunity($type, $subject, 'Title ' . $subject, 'Why', [['label' => 'Impressions', 'value' => '1,000']], $impact, 50, 1);

        self::assertNull($repo->lastAnalyzedAt($project));
        self::assertSame(['opened' => 3, 'updated' => 0, 'resolved' => 0], $repo->sync($project, [$o('query:a', 80), $o('query:b', 40), $o('query:c', 60, OpportunityType::ContentGap), $o('query:a', 10)]));
        self::assertNotNull($repo->lastAnalyzedAt($project));
        self::assertSame(['Title query:a', 'Title query:c', 'Title query:b'], array_map(static fn($s): string => $s->title, $repo->list($project, 'open', null, 10, 0)), 'By priority; a duplicate subject counts once.');
        self::assertEquals(['low_ctr' => 2, 'content_gap' => 1], $repo->openByType($project));
        self::assertSame(1, $repo->count($project, 'open', OpportunityType::ContentGap));
        self::assertSame([['label' => 'Impressions', 'value' => '1,000']], $repo->list($project, 'open', null, 1, 0)[0]->evidence);

        $a = $repo->list($project, 'open', null, 1, 0)[0];
        $b = $repo->list($project, 'open', null, 10, 0)[2];
        self::assertTrue($repo->setStatus($project, $a->publicId, 'dismissed', $acme['user']->id));
        self::assertFalse($repo->setStatus($other, $a->publicId, 'done', $acme['user']->id), 'Scoped to the project.');

        // b is no longer detected -> resolved; a stays dismissed; c is updated.
        self::assertSame(['opened' => 0, 'updated' => 2, 'resolved' => 1], $repo->sync($project, [$o('query:a', 90), $o('query:c', 70, OpportunityType::ContentGap)]));
        self::assertSame('dismissed', $repo->find($project, $a->publicId)?->status);
        self::assertSame(90, $repo->find($project, $a->publicId)->impact, 'Evidence and scores are refreshed even when dismissed.');
        self::assertSame('resolved', $repo->find($project, $b->publicId)?->status);

        // b is detected again -> reopened.
        self::assertSame(['opened' => 0, 'updated' => 3, 'resolved' => 0], $repo->sync($project, [$o('query:a', 90), $o('query:b', 40), $o('query:c', 70, OpportunityType::ContentGap)]));
        self::assertSame('open', $repo->find($project, $b->publicId)?->status);

        $history = $this->db->fetchAll(
            'SELECT h.from_status, h.to_status FROM opportunity_status_history h JOIN opportunities o ON o.id = h.opportunity_id WHERE o.public_id = ? ORDER BY h.id',
            [$b->publicId],
        );
        self::assertSame([[null, 'open'], ['open', 'resolved'], ['resolved', 'open']], array_map(static fn(array $r): array => [$r['from_status'], $r['to_status']], $history));

        self::assertSame([$project, $other], array_column($repo->projectsToAnalyze(), 'project_id'));
        $this->db->execute('UPDATE projects SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$other]);
        self::assertSame([$project], array_column($repo->projectsToAnalyze(), 'project_id'));
    }

    public function testAnalyzerUsesStoredSearchConsoleData(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($acme['workspace']);
        $ws = $acme['workspace']->id;
        $project = $this->project($ws, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fa3');
        $store = $this->app->make(SearchPerformanceStore::class);
        $store->upsertDailyTotals($project, [new DailyMetrics($this->app->make(\SeoControlCenter\Core\Support\Clock::class)->now()->modify('-2 days')->format('Y-m-d'), new Metrics(10, 2000, 8.0))]);
        $store->replaceDay($project, $this->app->make(\SeoControlCenter\Core\Support\Clock::class)->now()->modify('-2 days')->format('Y-m-d'), [
            'query' => [new DimensionRow('weak snippet', new Metrics(4, 1000, 2.0)), new DimensionRow('deep topic', new Metrics(0, 400, 34.0))],
            'page' => [], 'device' => [], 'country' => [],
        ]);
        $analyzer = $this->app->make(OpportunityAnalyzer::class);

        self::assertSame(['opened' => 2, 'updated' => 0, 'resolved' => 0], $analyzer->analyze($project, $ws));
        self::assertNull($analyzer->analyze($project, $ws + 1000), 'Wrong workspace: nothing is analyzed.');
        $types = array_map(static fn($o): string => $o->type->value, $this->app->make(OpportunityRepository::class)->list($project, 'open', null, 10, 0));
        self::assertEqualsCanonicalizing(['low_ctr', 'content_gap'], $types);
    }

    public function testCatalogAndStructuredEstimatePreserveWorkflowAndScope(): void
    {
        $owner = $this->createUser('catalog@acme.test', 'Catalog');
        self::assertNotNull($owner['workspace']);
        $project = $this->project($owner['workspace']->id, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fe1');
        $other = $this->project($owner['workspace']->id, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fe2');
        $repo = $this->app->make(OpportunityRepository::class);
        $opportunity = new Opportunity(OpportunityType::QuickWin, 'query:red shoes', 'Review', 'Review', [['label' => 'Query', 'value' => 'red shoes']], 50, 80, 1, 123.5);
        $repo->sync($project, [$opportunity]);
        $row = $repo->catalog($project)['rows'][0];
        self::assertSame(123.5, $row->estimatedClicks);
        $repo->setStatus($project, $row->publicId, 'done', $owner['user']->id);
        $repo->sync($project, [$opportunity]);
        self::assertSame($row->publicId, $repo->catalog($project)['rows'][0]->publicId);
        self::assertSame('done', $repo->catalog($project)['rows'][0]->status);
        self::assertSame(['rows' => [], 'total' => 0], $repo->catalog($other));
        self::assertSame(1, $repo->catalog($project)['total']);
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
