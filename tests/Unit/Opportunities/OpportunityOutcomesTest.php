<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\OutcomeCalibration;
use SeoControlCenter\Domain\Opportunities\OutcomeCalibrator;
use SeoControlCenter\Domain\Opportunities\OutcomeMetrics;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;
use SeoControlCenter\Modules\Opportunities\OutcomeService;
use SeoControlCenter\Tests\Support\Fakes\InMemoryOutcomeMeasurements;
use SeoControlCenter\Tests\Support\Fakes\InMemoryOutcomes;
use SeoControlCenter\Tests\Support\Scenario;

final class OpportunityOutcomesTest extends TestCase
{
    public function testCalibrationHasMinimumCohortNeutralPriorAndBounds(): void
    {
        self::assertSame(1.0, OutcomeCalibration::factor(0, 4));
        self::assertEqualsWithDelta(2 / 3, OutcomeCalibration::factor(0, 5), .000001);
        self::assertSame(.25, OutcomeCalibration::factor(-20, 1000));
        self::assertSame(1.5, OutcomeCalibration::factor(20, 1000));
        $row = new Opportunity(OpportunityType::QuickWin, 'query:shoes', 'Review', 'Review', [], 50, 80, 1, 100);
        $adjusted = (new OutcomeCalibrator(['quick_win' => ['factor' => .5, 'samples' => 10]]))->adjust($row);
        self::assertSame(50.0, $adjusted->estimatedClicks);
        self::assertSame(100.0, $adjusted->rawEstimatedClicks);
        self::assertSame(50.0, (new OutcomeCalibrator(['quick_win' => ['factor' => .5, 'samples' => 10]]))->adjust($adjusted)->estimatedClicks, 'Reapplying calibration must use the original estimate.');
        self::assertSame(Opportunity::impactFromClicks(50), $adjusted->impact);
        self::assertStringContainsString('Correlation, not attribution', $adjusted->evidence[0]['value']);
    }

    public function testCompletionIsIdempotentAndMeasuresTheCorrectTwoWindows(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-08 12:00:00 UTC'));
        $scenario = new Scenario(clock: $clock);
        $repository = new InMemoryOutcomes($clock);
        $measurements = new InMemoryOutcomeMeasurements();
        $service = new OutcomeService($repository, $measurements, $clock);
        $row = new StoredOpportunity('finding-id', OpportunityType::QuickWin, 'Review', 'Review', [['label' => 'Query', 'value' => 'shoes']], 50, 80, 1, 40, 'done', '2026-10-01', '2026-10-08', 150);
        $project = $scenario->acmeProject;
        $measurements->measurements[$project->id]['query:shoes']['2026-09-10:2026-10-07'] = new OutcomeMetrics(100, 1000, 8);
        $service->completed($project, $row, 'annotation-id', 'finding');
        $service->completed($project, $row, 'annotation-id', 'task');
        self::assertCount(2, $repository->recent($project->id));
        self::assertSame([], $repository->recent($scenario->rivalProject->id));
        $clock->advance('+30 days');
        self::assertSame(0, $service->measureDue(), 'Missing after data stays pending.');
        self::assertSame(0, $service->measureDue(), 'A missing row is checked at most once per day.');
        $measurements->measurements[$project->id]['query:shoes']['2026-10-09:2026-11-05'] = new OutcomeMetrics(240, 2000, 5);
        $clock->advance('+1 day');
        self::assertSame(1, $service->measureDue());
        $actualRows = array_values(array_filter($repository->recent($project->id), static fn($row): bool => $row->checkpoint === 28));
        self::assertCount(1, $actualRows);
        $actual = $actualRows[0];
        self::assertSame(150.0, $actual->monthlyChange());
        self::assertSame(.12, $actual->actual?->ctr());
        self::assertSame('annotation-id', $actual->annotationId);
        $measurements->measurements[$project->id]['query:shoes']['2026-11-06:2026-12-03'] = new OutcomeMetrics(170, 2000, 6);
        $clock->advance('+27 days');
        self::assertSame(1, $service->measureDue());
        self::assertSame(['factor' => 1.0, 'samples' => 1], $repository->factors($project->id, '2026-01-01')['quick_win']);
    }
}
