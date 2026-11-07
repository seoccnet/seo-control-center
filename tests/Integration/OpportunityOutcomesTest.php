<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\OutcomeMeasurementReader;
use SeoControlCenter\Domain\Opportunities\OutcomeRepository;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Opportunities\OutcomeService;

final class OpportunityOutcomesTest extends FullStackTestCase
{
    public function testScopedIdempotentOutcomesCompleteCoverageAndBothCheckpoints(): void
    {
        $owner = $this->createUser('outcomes@acme.test', 'Outcomes');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace']->id;
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", ['0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fa1', $workspace]);
        $project = $this->db->lastInsertId();
        $reader = $this->app->make(OutcomeMeasurementReader::class);
        $store = $this->app->make(SearchPerformanceStore::class);
        foreach (['2026-08-07' => 280, '2026-09-05' => 420, '2026-10-03' => 350] as $date => $clicks) {
            $store->replaceDay($project, $date, ['query' => [new DimensionRow(' Red   Shoes ', new Metrics($clicks, 2800, 8))], 'page' => [new DimensionRow('https://shop.test/shoes/', new Metrics($clicks, 2800, 8))], 'device' => [], 'country' => []]);
            $range = DateRange::endingOn($date, 28);
            for ($day = $range->from; $day <= $range->to; $day = $day->modify('+1 day')) {
                $this->db->execute('INSERT IGNORE INTO search_console_import_days (project_id, date, imported_at) VALUES (?, ?, ?)', [$project, $day->format('Y-m-d'), '2026-10-08 00:00:00']);
            }
        }
        $baselineRange = DateRange::endingOn('2026-08-07', 28);
        $baseline = $reader->measure($project, 'query:red shoes', $baselineRange);
        self::assertNotNull($baseline);
        self::assertSame(280, $baseline->clicks);
        self::assertSame(.1, $baseline->ctr());
        self::assertSame(280, $reader->measure($project, 'page:shop.test/shoes', $baselineRange)?->clicks);
        self::assertSame($baseline?->clicks, $reader->measure($project, 'brand:red shoes', $baselineRange)?->clicks, 'A brand search is measured like any search (finding 43).');
        self::assertNull($reader->measure($project + 1000, 'query:red shoes', $baselineRange));
        self::assertNull($reader->measure($project, 'query:missing subject', $baselineRange));
        $opportunities = $this->app->make(OpportunityRepository::class);
        $opportunities->sync($project, [new Opportunity(OpportunityType::QuickWin, 'query:red shoes', 'Review', 'Review', [['label' => 'Query', 'value' => 'red shoes']], 50, 80, 1, 150)]);
        $opportunity = $opportunities->catalog($project)['rows'][0];
        $opportunities->setStatus($project, $opportunity->publicId, 'done', $owner['user']->id);
        $repository = $this->app->make(OutcomeRepository::class);
        $repository->record($project, $workspace + 1000, $opportunity, '2026-08-08', $baseline, null, 'finding');
        self::assertSame([], $repository->recent($project));
        $repository->record($project, $workspace, $opportunity, '2026-08-08', $baseline, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fa9', 'finding');
        $repository->record($project, $workspace, $opportunity, '2026-08-08', $baseline, null, 'task');
        self::assertCount(2, $repository->recent($project));
        self::assertNull($repository->recent($project)[0]->annotationId, 'A foreign or removed annotation cannot be linked.');
        $service = new OutcomeService($repository, $reader, new FrozenClock(new \DateTimeImmutable('2026-10-08 12:00:00 UTC')));
        self::assertSame(2, $service->measureDue());
        self::assertSame(0, $service->measureDue());
        $rows = $repository->recent($project);
        $day28Rows = array_values(array_filter($rows, static fn($row): bool => $row->checkpoint === 28));
        self::assertCount(1, $day28Rows);
        $day28 = $day28Rows[0];
        self::assertSame(150.0, $day28->monthlyChange());
        self::assertSame(150.0, $day28->predictedClicks);
        self::assertSame(['factor' => 1.0, 'samples' => 1], $repository->factors($project, '2026-01-01')['quick_win']);
        self::assertSame([], $repository->recent($project + 1000));
        $this->db->execute('DELETE FROM search_console_import_days WHERE project_id = ? AND date = ?', [$project, '2026-07-12']);
        self::assertNull($reader->measure($project, 'query:red shoes', $baselineRange), 'An absent daily mark makes the baseline unknown, even if aggregate subject rows exist.');
    }
}
