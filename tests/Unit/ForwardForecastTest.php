<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\Forecasting\ForecastCalibration;
use SeoControlCenter\Modules\Forecasting\ForecastEngine;
use SeoControlCenter\Modules\Forecasting\ForecastEvent;
use SeoControlCenter\Modules\Forecasting\ForecastSeries;

final class ForwardForecastTest extends TestCase
{
    public function testWeeklyPatternAndDisjointTestsAtAllHorizons(): void
    {
        $values = $this->values(200);
        foreach ([7, 14, 28] as $horizon) {
            $r = (new ForecastEngine())->predict(new ForecastSeries('a', 'Weekly visits', 'b', $values), '2026-07-20', $horizon);
            self::assertSame('Weekday average', $r['method']);
            self::assertSame(0.0, $r['mae']);
            self::assertGreaterThanOrEqual(4, $r['folds']);
            self::assertCount($horizon, $r['points']);
            self::assertSame('2026-07-21', array_key_first($r['points']));
            foreach ($r['points'] as $date => $v) {
                self::assertSame(10.0 + (float) (new \DateTimeImmutable($date))->format('w') * 20, $v);
            }
        }
    }
    public function testMissingStaleRestrictedAndFutureHistory(): void
    {
        $engine = new ForecastEngine();
        $values = $this->values(200);
        self::assertSame([], $engine->predict(new ForecastSeries('a', 'a', 'b', []), '2026-07-20', 14)['points']);
        self::assertSame([], $engine->predict(new ForecastSeries('a', 'a', 'b', $values), '2026-08-20', 14)['points']);
        self::assertSame([], $engine->predict(new ForecastSeries('a', 'a', 'b', $values, 'Thresholded'), '2026-07-20', 14)['points']);
        $known = $engine->predict(new ForecastSeries('a', 'a', 'b', $values), '2026-07-20', 14);
        $values['2026-07-30'] = 999999.0;
        self::assertSame($known, $engine->predict(new ForecastSeries('a', 'a', 'b', $values), '2026-07-20', 14));
        $sparse = array_filter($values, static fn(string $date): bool => (int) substr($date, 8) % 2 === 0, ARRAY_FILTER_USE_KEY);
        self::assertSame([], $engine->predict(new ForecastSeries('a', 'a', 'b', $sparse), '2026-07-20', 14)['points']);
        $leadingGap = array_filter($values, static fn(string $date): bool => $date < '2026-06-21' || $date >= '2026-07-06', ARRAY_FILTER_USE_KEY);
        $gated = $engine->predict(new ForecastSeries('a', 'a', 'b', $leadingGap), '2026-07-20', 14);
        self::assertSame([], $gated['points'], 'Missing leading fit dates must count against coverage.');
        self::assertStringContainsString('90%', (string) $gated['reason']);
    }
    public function testForwardCalibrationExcludesOverlappingWindowsAndNeedsTwenty(): void
    {
        $samples = [];
        for ($i = 0; $i < 20; $i++) {
            $issued = ForecastEngine::date('2026-01-01', $i * 7);
            $samples[] = ['issued' => $issued, 'target' => ForecastEngine::date($issued, 7), 'prediction' => 10.0, 'actual' => 10.0 + $i];
            $samples[] = ['issued' => ForecastEngine::date($issued, 1), 'target' => ForecastEngine::date($issued, 8), 'prediction' => 999999.0, 'actual' => 0.0];
        }
        $calibrator = new ForecastCalibration();
        $result = $calibrator->summarize($samples);
        self::assertSame(20, $result['sample']);
        self::assertSame(16.0, $result['radius']);
        self::assertSame(9.5, $result['mae']);
        self::assertNull($calibrator->summarize(array_slice($samples, 0, 38))['radius']);
    }

    public function testAnnualCandidateNeedsPreviousYearCoverageAndSharedTests(): void
    {
        $values = [];
        for ($i = 0; $i < 650; $i++) {
            $date = ForecastEngine::date('2024-03-01', $i);
            $monthDay = (int) (new \DateTimeImmutable($date))->format('md');
            $values[$date] = 10.0 + ($monthDay * 29 % 97);
        }
        $engine = new ForecastEngine();
        $series = new ForecastSeries('a', 'Annual visits', 'b', $values);
        $result = $engine->predict($series, '2025-12-10', 28);
        self::assertSame('Previous-year calendar pattern', $result['method']);
        self::assertSame(0.0, $result['mae']);
        self::assertGreaterThanOrEqual(4, $result['folds']);
        foreach ($result['points'] as $date => $value) {
            self::assertSame($values[(new \DateTimeImmutable($date))->modify('-1 year')->format('Y-m-d')], $value);
        }
        $short = $engine->predict(new ForecastSeries('a', 'a', 'b', array_slice($values, -180, preserve_keys: true)), '2025-12-10', 28);
        self::assertArrayNotHasKey('annual', $short['candidates']);
        unset($values['2024-12-11']);
        $missing = $engine->predict(new ForecastSeries('a', 'a', 'b', $values), '2025-12-10', 28);
        self::assertArrayNotHasKey('annual', $missing['candidates']);
    }

    public function testCalendarUsesKnownDatesAndDoesNotLeakNewlyEnteredHistory(): void
    {
        $values = $this->values(200);
        $calendar = [];
        for ($i = 0; $i < 240; $i += 11) {
            $date = ForecastEngine::date('2026-01-01', $i);
            $calendar[] = new ForecastEvent('Scheduled event', $date, '2025-12-01');
            if (isset($values[$date])) {
                $values[$date] = 500.0;
            }
        }
        $engine = new ForecastEngine();
        $series = new ForecastSeries('a', 'a', 'b', $values);
        $r = $engine->predict($series, '2026-07-20', 14, $calendar);
        self::assertSame('Recorded event calendar', $r['method']);
        self::assertSame(0.0, $r['mae']);
        self::assertGreaterThanOrEqual(4, $r['folds']);
        $newlyKnown = array_map(static fn(ForecastEvent $e): ForecastEvent => new ForecastEvent($e->name, $e->date, '2026-07-20'), $calendar);
        $gated = $engine->predict($series, '2026-07-20', 14, $newlyKnown);
        self::assertArrayNotHasKey('calendar', $gated['candidates'], 'A newly entered past date is not past knowledge.');
        self::assertNotSame('Recorded event calendar', $gated['method']);
        $missingEvents = $values;
        foreach ($calendar as $event) {
            unset($missingEvents[$event->date]);
        }
        $missing = $engine->predict(new ForecastSeries('a', 'a', 'b', $missingEvents), '2026-07-20', 14, $calendar);
        self::assertArrayNotHasKey('calendar', $missing['candidates'], 'Unknown event outcomes cannot validate event assistance.');
        $conflicting = [...$calendar, new ForecastEvent('Different event', $calendar[0]->date, '2025-12-01')];
        self::assertArrayNotHasKey('calendar', $engine->predict($series, '2026-07-20', 14, $conflicting)['candidates']);
    }
    /** @return array<string, float> */
    private function values(int $n): array
    {
        $values = [];
        for ($i = 0; $i < $n; $i++) {
            $date = ForecastEngine::date('2026-01-01', $i);
            $values[$date] = 10.0 + (float) (new \DateTimeImmutable($date))->format('w') * 20;
        }
        return $values;
    }
}
