<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Measurement;

/**
 * Reads a measurement plan's outcome (ADR-042, 0.39.0) from its frozen
 * baseline and the period after the change. A result is given only when
 * every readiness gate passes; otherwise the plan stays planned, pending or
 * short of evidence. "Controlled" needs control pages measured over the same
 * periods; without them a change is an association, never a cause.
 *
 * @phpstan-type Metrics array{clicks: ?int, impressions: ?int, ctr: ?float, position: ?float, sessions: ?int, conversions: ?float}
 * @phpstan-type Snapshot array{from: string, to: string, expected_days: int, google_days: int, google_complete: bool, analytics_days: int, analytics_complete: bool, missing: list<string>, metrics: Metrics}
 * @phpstan-type Gate array{key: string, label: string, passed: bool, detail: string}
 * @phpstan-type Side array{before: float, after: float, change: float}
 * @phpstan-type Result array{status: string, label: string, reason: string, gates: list<Gate>, target: ?Side, control: ?Side, effect: ?float, unit: string, attribution: ?string, goal: ?array{goal: float, value: float}}
 */
final class MeasurementResult
{
    public const array METRICS = ['clicks' => 'Clicks', 'impressions' => 'Impressions', 'ctr' => 'CTR', 'position' => 'Average position', 'sessions' => 'Organic sessions (GA4)', 'conversions' => 'Key events (GA4)'];
    public const array ANALYTICS = ['sessions', 'conversions'];
    public const array STATUSES = ['planned' => 'Planned', 'pending' => 'Pending', 'insufficient_evidence' => 'Insufficient evidence', 'improved' => 'Improved', 'declined' => 'Declined', 'no_clear_change' => 'No clear change', 'reached' => 'Goal reached', 'not_reached' => 'Goal not reached'];
    /** A relative change smaller than this (or half a position) is not read as a change. */
    public const float THRESHOLD = 5.0;
    public const float POSITION_THRESHOLD = 0.5;
    /** Below this volume before the change, a change cannot be read. */
    public const int MIN_IMPRESSIONS = 100;
    public const int MIN_SESSIONS = 30;

    /**
     * @param Snapshot|null $baseline the frozen targets before the change
     * @param Snapshot|null $controlBaseline the frozen control pages before the change
     * @param Snapshot|null $after the targets after the change (null before the change)
     * @param Snapshot|null $controlAfter the control pages after the change
     * @param float|null $goal an after-only target for the primary metric (0.39.1): at least this, or at most this for average position
     * @return Result
     */
    public static function evaluate(string $changeDate, int $days, string $metric, ?array $baseline, ?array $controlBaseline, ?array $after, ?array $controlAfter, string $today, ?string $dataLast, ?float $goal = null): array
    {
        $ga4 = in_array($metric, self::ANALYTICS, true);
        $afterTo = (new \DateTimeImmutable($changeDate))->modify('+' . $days . ' days')->format('Y-m-d');
        // Complete means every day imported and every target measured: a target without data is a gap, not a zero.
        $complete = static fn(?array $s): bool => $s !== null && ($ga4 ? $s['analytics_complete'] : $s['google_complete']) && $s['missing'] === [];
        $covered = static fn(?array $s): int => $s === null ? 0 : ($ga4 ? $s['analytics_days'] : $s['google_days']);
        $implemented = $changeDate <= $today;
        $elapsed = $afterTo < $today;
        $gates = [
            ['key' => 'implemented', 'label' => 'Change made', 'passed' => $implemented, 'detail' => $implemented ? 'Recorded on ' . $changeDate . '.' : 'The change is planned for ' . $changeDate . '.'],
            ['key' => 'baseline', 'label' => 'Baseline frozen and complete', 'passed' => $complete($baseline), 'detail' => $baseline === null ? 'Freeze the baseline once the period before the change is imported.' : sprintf('%d of %d days before the change imported%s.', $covered($baseline), $baseline['expected_days'], $baseline['missing'] !== [] ? '; no data for ' . count($baseline['missing']) . ' target(s)' : '')],
            ['key' => 'window', 'label' => 'Period after the change over', 'passed' => $elapsed, 'detail' => $elapsed ? sprintf('%d days after the change, to %s.', $days, $afterTo) : sprintf('Ends on %s.', $afterTo)],
            ['key' => 'imported', 'label' => 'Period after the change imported', 'passed' => $complete($after), 'detail' => sprintf('%d of %d days imported%s%s.', $covered($after), $days, $dataLast !== null ? ' (Search Console data to ' . $dataLast . ')' : '', $after !== null && $after['missing'] !== [] ? '; no data for ' . count($after['missing']) . ' target(s)' : '')],
        ];
        // An after-only goal needs no baseline: a new page has nothing before the change.
        if ($goal !== null) {
            $gates = array_values(array_filter($gates, static fn(array $g): bool => $g['key'] !== 'baseline'));
        }
        $unit = $metric === 'position' ? 'positions' : '%';
        $result = static fn(string $status, string $reason): array => self::result($status, $reason, $gates, $unit);

        if (!$implemented) {
            return $result('planned', 'The change has not been made yet.');
        }
        // Waiting is not a verdict: the window is still open, or imports have not reached its end yet.
        if (!$elapsed || (!$complete($after) && ($dataLast === null || $dataLast < $afterTo))) {
            return $result('pending', sprintf('Only %d of %d days after the change are imported so far. A partial period is not compared.', $covered($after), $days));
        }
        if ($goal !== null) {
            $value = $after !== null && $complete($after) ? ($after['metrics'][$metric] ?? null) : null;
            if ($value === null) {
                return $result('insufficient_evidence', 'Days or targets are missing from the period after the change; a target without data may not be indexed yet.');
            }
            $reached = $metric === 'position' ? (float) $value <= $goal : (float) $value >= $goal;

            return self::result($reached ? 'reached' : 'not_reached', sprintf('Goal: %s %s; measured %s over the %d days after the change. A goal shows whether a level was reached, not what the change caused.', $metric === 'position' ? 'at most' : 'at least', self::format($goal), self::format((float) $value), $days), $gates, $unit, goal: ['goal' => $goal, 'value' => round((float) $value, 2)]);
        }
        if ($baseline === null) {
            return $result('insufficient_evidence', 'No baseline was frozen, so there is nothing fixed to compare with.');
        }
        if ($baseline['missing'] !== [] && ($baseline['metrics']['impressions'] ?? 0) === 0) {
            return $result('insufficient_evidence', 'The targets had no data before the change, for example a page that did not exist yet. Set an after-only goal for them instead.');
        }
        if ($after === null || !$complete($baseline) || !$complete($after)) {
            return $result('insufficient_evidence', 'Days or targets are missing from the period before or after the change; a comparison would mix measured data with gaps.');
        }
        $volume = $ga4 ? ($baseline['metrics']['sessions'] ?? 0) : ($baseline['metrics']['impressions'] ?? 0);
        if ($volume < ($ga4 ? self::MIN_SESSIONS : self::MIN_IMPRESSIONS)) {
            return $result('insufficient_evidence', sprintf('Only %d %s before the change; too little to read a change.', $volume, $ga4 ? 'sessions' : 'impressions'));
        }
        $target = self::side($metric, $baseline, $after);
        if ($target === null) {
            return $result('insufficient_evidence', 'The primary metric has no value before or after the change.');
        }
        $control = $controlBaseline !== null && $controlAfter !== null && $complete($controlBaseline) && $complete($controlAfter) ? self::side($metric, $controlBaseline, $controlAfter) : null;
        // Better is positive: a lower average position is an improvement.
        $better = static fn(array $s): float => $metric === 'position' ? -$s['change'] : $s['change'];
        $effect = $control !== null ? $better($target) - $better($control) : $better($target);
        $threshold = $metric === 'position' ? self::POSITION_THRESHOLD : self::THRESHOLD;
        $status = $effect >= $threshold ? 'improved' : ($effect <= -$threshold ? 'declined' : 'no_clear_change');
        $reason = $control !== null
            ? 'Compared with the control pages over the same periods: the difference between the two is the effect.'
            : 'Without control pages this is an association: seasonality, competitors, search updates and other changes may explain it.';

        return self::result($status, $reason, $gates, $unit, $target, $control, round($effect, 2), $control !== null ? 'controlled' : 'association');
    }

    /**
     * @param list<Gate> $gates
     * @param Side|null $target
     * @param Side|null $control
     * @param array{goal: float, value: float}|null $goal
     * @return Result
     */
    private static function result(string $status, string $reason, array $gates, string $unit, ?array $target = null, ?array $control = null, ?float $effect = null, ?string $attribution = null, ?array $goal = null): array
    {
        return ['status' => $status, 'label' => self::STATUSES[$status] ?? $status, 'reason' => $reason, 'gates' => $gates, 'target' => $target, 'control' => $control, 'effect' => $effect, 'unit' => $unit, 'attribution' => $attribution, 'goal' => $goal];
    }

    private static function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }

    /**
     * @param Snapshot $before
     * @param Snapshot $after
     * @return Side|null
     */
    private static function side(string $metric, array $before, array $after): ?array
    {
        $a = $before['metrics'][$metric] ?? null;
        $b = $after['metrics'][$metric] ?? null;
        if ($a === null || $b === null || ($metric !== 'position' && (float) $a <= 0.0)) {
            return null;
        }
        $change = $metric === 'position' ? (float) $b - (float) $a : ((float) $b - (float) $a) / (float) $a * 100;

        return ['before' => round((float) $a, 2), 'after' => round((float) $b, 2), 'change' => round($change, 2)];
    }
}
