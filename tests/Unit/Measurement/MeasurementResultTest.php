<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Measurement;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Measurement\MeasurementResult;

/** Measurement plans (ADR-042, 0.39.0): a result only once every gate passes. */
final class MeasurementResultTest extends TestCase
{
    private const string CHANGE = '2026-09-01';

    public function testOnlyTwoPostChangeDaysImportedStaysPending(): void
    {
        $after = self::snapshot('2026-09-02', 2, 28, ['clicks' => 30, 'impressions' => 900]);
        // The window is over, but Search Console has only reached two days after the change.
        $result = MeasurementResult::evaluate(self::CHANGE, 28, 'clicks', self::snapshot('2026-08-04', 28, 28), null, $after, null, '2026-10-20', '2026-09-03');
        self::assertSame('pending', $result['status']);
        self::assertSame('Only 2 of 28 days after the change are imported so far. A partial period is not compared.', $result['reason']);
        self::assertSame([true, true, true, false], array_column($result['gates'], 'passed'));
        self::assertNull($result['target']);

        self::assertSame('pending', MeasurementResult::evaluate(self::CHANGE, 28, 'clicks', self::snapshot('2026-08-04', 28, 28), null, $after, null, '2026-09-10', '2026-09-08')['status'], 'The window is still open.');
        self::assertSame('planned', MeasurementResult::evaluate('2026-11-01', 28, 'clicks', null, null, null, null, '2026-10-20', '2026-10-17')['status']);
    }

    public function testMissingOrThinEvidenceIsSaidSo(): void
    {
        $after = self::snapshot('2026-09-02', 28, 28);
        self::assertSame('insufficient_evidence', self::read(null, $after)['status'], 'No frozen baseline.');
        self::assertSame('insufficient_evidence', self::read(self::snapshot('2026-08-04', 25, 28), $after)['status'], 'Days missing before the change.');
        self::assertSame('insufficient_evidence', self::read(self::snapshot('2026-08-04', 28, 28), self::snapshot('2026-09-02', 27, 28))['status'], 'Days missing after the change once imports passed its end.');
        $thin = self::read(self::snapshot('2026-08-04', 28, 28, ['clicks' => 2, 'impressions' => 60]), $after);
        self::assertSame(['insufficient_evidence', 'Only 60 impressions before the change; too little to read a change.'], [$thin['status'], $thin['reason']]);
        self::assertSame('insufficient_evidence', self::read(self::snapshot('2026-08-04', 28, 28, ['clicks' => 0]), $after)['status'], 'No clicks before: no relative change.');
        $gap = self::snapshot('2026-08-04', 28, 28);
        $gap['missing'] = ['https://s.test/new-page'];
        $read = self::read($gap, $after);
        self::assertSame(['insufficient_evidence', false], [$read['status'], $read['gates'][1]['passed']], 'A target without data before the change (a new page) is a gap, not a zero.');
    }

    public function testDirectionThresholdAndAssociation(): void
    {
        $before = self::snapshot('2026-08-04', 28, 28, ['clicks' => 100]);
        $up = self::read($before, self::snapshot('2026-09-02', 28, 28, ['clicks' => 120]));
        self::assertSame(['improved', 'association', 20.0], [$up['status'], $up['attribution'], $up['effect']]);
        self::assertStringContainsString('association', $up['reason']);
        self::assertSame(['before' => 100.0, 'after' => 120.0, 'change' => 20.0], $up['target']);
        self::assertSame('declined', self::read($before, self::snapshot('2026-09-02', 28, 28, ['clicks' => 80]))['status']);
        self::assertSame('no_clear_change', self::read($before, self::snapshot('2026-09-02', 28, 28, ['clicks' => 104]))['status'], 'Under 5 % is no clear change.');

        $position = MeasurementResult::evaluate(self::CHANGE, 28, 'position', self::snapshot('2026-08-04', 28, 28, ['position' => 9.0]), null, self::snapshot('2026-09-02', 28, 28, ['position' => 7.8]), null, '2026-10-20', '2026-10-17');
        self::assertSame(['improved', 1.2, 'positions'], [$position['status'], $position['effect'], $position['unit']], 'A lower average position is better.');
    }

    public function testControlPagesMakeItControlledAndTakeOutTheCommonTrend(): void
    {
        $before = self::snapshot('2026-08-04', 28, 28, ['clicks' => 100]);
        $after = self::snapshot('2026-09-02', 28, 28, ['clicks' => 120]);
        $season = MeasurementResult::evaluate(self::CHANGE, 28, 'clicks', $before, self::snapshot('2026-08-04', 28, 28, ['clicks' => 200]), $after, self::snapshot('2026-09-02', 28, 28, ['clicks' => 236]), '2026-10-20', '2026-10-17');
        self::assertSame(['no_clear_change', 'controlled', 2.0], [$season['status'], $season['attribution'], $season['effect']], 'Controls rose 18 %, so the targets\' 20 % is mostly the season.');
        self::assertSame(['before' => 200.0, 'after' => 236.0, 'change' => 18.0], $season['control']);
        $gap = MeasurementResult::evaluate(self::CHANGE, 28, 'clicks', $before, self::snapshot('2026-08-04', 20, 28, ['clicks' => 200]), $after, self::snapshot('2026-09-02', 28, 28, ['clicks' => 236]), '2026-10-20', '2026-10-17');
        self::assertSame(['improved', 'association'], [$gap['status'], $gap['attribution']], 'Incomplete controls are not used.');
    }

    public function testGa4MetricsUseAnalyticsCompleteness(): void
    {
        $before = self::snapshot('2026-08-04', 28, 28, ['sessions' => 200], analytics: false);
        $after = self::snapshot('2026-09-02', 28, 28, ['sessions' => 260]);
        self::assertSame('insufficient_evidence', MeasurementResult::evaluate(self::CHANGE, 28, 'sessions', $before, null, $after, null, '2026-10-20', '2026-10-17')['status']);
        $before = self::snapshot('2026-08-04', 28, 28, ['sessions' => 200]);
        self::assertSame(['improved', 30.0], array_values(array_intersect_key(MeasurementResult::evaluate(self::CHANGE, 28, 'sessions', $before, null, $after, null, '2026-10-20', '2026-10-17'), ['status' => 1, 'effect' => 1])));
    }

    public function testANewPageIsReadAgainstAnAfterOnlyGoal(): void
    {
        $empty = self::snapshot('2026-08-04', 28, 28, ['clicks' => 0, 'impressions' => 0]);
        $empty['missing'] = ['https://s.test/new-article'];
        $after = self::snapshot('2026-09-02', 28, 28, ['impressions' => 640]);
        $hint = self::read($empty, $after);
        self::assertSame('The targets had no data before the change, for example a page that did not exist yet. Set an after-only goal for them instead.', $hint['reason']);

        $reached = MeasurementResult::evaluate(self::CHANGE, 28, 'impressions', null, null, $after, null, '2026-10-20', '2026-10-17', 500.0);
        self::assertSame(['reached', 'Goal reached', ['goal' => 500.0, 'value' => 640.0], null], [$reached['status'], $reached['label'], $reached['goal'], $reached['attribution']]);
        self::assertSame(['implemented', 'window', 'imported'], array_column($reached['gates'], 'key'), 'A goal needs no baseline.');
        self::assertSame('Goal: at least 500; measured 640 over the 28 days after the change. A goal shows whether a level was reached, not what the change caused.', $reached['reason']);
        self::assertSame('not_reached', MeasurementResult::evaluate(self::CHANGE, 28, 'impressions', null, null, $after, null, '2026-10-20', '2026-10-17', 1000.0)['status']);
        self::assertSame('reached', MeasurementResult::evaluate(self::CHANGE, 28, 'position', null, null, self::snapshot('2026-09-02', 28, 28, ['position' => 9.4]), null, '2026-10-20', '2026-10-17', 10.0)['status'], 'At most this position.');
        self::assertSame('pending', MeasurementResult::evaluate(self::CHANGE, 28, 'impressions', null, null, self::snapshot('2026-09-02', 2, 28), null, '2026-10-20', '2026-09-03', 500.0)['status'], 'The same import gates apply.');
        $unindexed = $after;
        $unindexed['missing'] = ['https://s.test/new-article'];
        self::assertSame('insufficient_evidence', MeasurementResult::evaluate(self::CHANGE, 28, 'impressions', null, null, $unindexed, null, '2026-10-20', '2026-10-17', 500.0)['status'], 'No data after the change: perhaps not indexed yet.');
    }

    /**
     * @param array{from: string, to: string, expected_days: int, google_days: int, google_complete: bool, analytics_days: int, analytics_complete: bool, missing: list<string>, metrics: array{clicks: ?int, impressions: ?int, ctr: ?float, position: ?float, sessions: ?int, conversions: ?float}}|null $before
     * @param array{from: string, to: string, expected_days: int, google_days: int, google_complete: bool, analytics_days: int, analytics_complete: bool, missing: list<string>, metrics: array{clicks: ?int, impressions: ?int, ctr: ?float, position: ?float, sessions: ?int, conversions: ?float}} $after
     * @return array<string, mixed>
     */
    private static function read(?array $before, array $after): array
    {
        return MeasurementResult::evaluate(self::CHANGE, 28, 'clicks', $before, null, $after, null, '2026-10-20', '2026-10-17');
    }

    /**
     * @param array<string, int|float> $metrics
     * @return array{from: string, to: string, expected_days: int, google_days: int, google_complete: bool, analytics_days: int, analytics_complete: bool, missing: list<string>, metrics: array{clicks: ?int, impressions: ?int, ctr: ?float, position: ?float, sessions: ?int, conversions: ?float}}
     */
    private static function snapshot(string $from, int $covered, int $days, array $metrics = [], bool $analytics = true): array
    {
        $m = ['clicks' => 100, 'impressions' => 2000, 'ctr' => 5.0, 'position' => 8.0, 'sessions' => 150, 'conversions' => 3.0];
        foreach ($metrics as $key => $value) {
            $m[$key] = $value;
        }

        return ['from' => $from, 'to' => (new \DateTimeImmutable($from))->modify('+' . ($days - 1) . ' days')->format('Y-m-d'), 'expected_days' => $days, 'google_days' => $covered, 'google_complete' => $covered === $days, 'analytics_days' => $analytics ? $covered : 0, 'analytics_complete' => $analytics && $covered === $days, 'missing' => [], 'metrics' => ['clicks' => (int) $m['clicks'], 'impressions' => (int) $m['impressions'], 'ctr' => (float) $m['ctr'], 'position' => (float) $m['position'], 'sessions' => (int) $m['sessions'], 'conversions' => (float) $m['conversions']]];
    }

    public function testTheResultsLedgerCountsOnlyGatedResultsAsMeasured(): void
    {
        // 0.45.0: insufficient evidence and open plans are never counted as "no change".
        $row = static fn(string $group, string $status, ?float $effect = null, string $unit = '%', bool $controlled = false): array => ['group' => $group, 'label' => ucfirst($group), 'status' => $status, 'effect' => $effect, 'unit' => $unit, 'controlled' => $controlled];
        $summary = \SeoControlCenter\Domain\Measurement\ResultsLedger::summarize([
            $row('title', 'improved', 25.0, '%', true),
            $row('title', 'improved', 10.0),
            $row('title', 'declined', -8.0),
            $row('title', 'no_clear_change', 2.0),
            $row('title', 'reached'),
            $row('title', 'insufficient_evidence'),
            $row('title', 'pending'),
            $row('links', 'improved', 1.5, 'positions'),
            $row('links', 'not_reached'),
        ]);
        self::assertSame(['title', 'links'], array_column($summary, 'group'), 'most measured first');
        self::assertSame(
            ['changes' => 7, 'measured' => 5, 'improved' => 3, 'declined' => 1, 'unchanged' => 1, 'insufficient' => 1, 'open' => 1, 'controlled' => 1, 'median_percent' => 6.0, 'median_positions' => null],
            array_intersect_key($summary[0], array_flip(['changes', 'measured', 'improved', 'declined', 'unchanged', 'insufficient', 'open', 'controlled', 'median_percent', 'median_positions'])),
        );
        self::assertSame('On your site: 3 of 5 measured changes improved, 1 declined, median +6 %.', \SeoControlCenter\Domain\Measurement\ResultsLedger::sentence($summary[0]));
        self::assertSame('On your site: 1 of 2 measured changes improved, 1 declined, median +1.5 positions; too few to show a pattern yet.', \SeoControlCenter\Domain\Measurement\ResultsLedger::sentence($summary[1]));
        self::assertNull(\SeoControlCenter\Domain\Measurement\ResultsLedger::sentence(\SeoControlCenter\Domain\Measurement\ResultsLedger::summarize([$row('content', 'pending')])[0]));
    }
}
