<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Forecasting;

/** Bounded server-side forecasts for immutable forward validation. No providers. */
final class ForecastEngine
{
    public const string VERSION = 'forward-1';
    public const array METHODS = ['mean' => 'Recent average', 'linear' => 'Linear trend', 'weekday' => 'Weekday average', 'annual' => 'Previous-year calendar pattern', 'calendar' => 'Recorded event calendar'];

    /**
     * @param list<ForecastEvent> $calendar
     * @return array{method: string, points: array<string, float>, mae: ?float, folds: int, observations: int, from: ?string, to: ?string, candidates: array<string, float>, reason: ?string}
     */
    public function predict(ForecastSeries $series, string $issued, int $horizon, array $calendar = []): array
    {
        $values = array_filter($series->values, static fn(float $v, string $d): bool => $d <= $issued && is_finite($v) && $v >= 0, ARRAY_FILTER_USE_BOTH);
        ksort($values);
        $result = ['method' => '', 'points' => [], 'mae' => null, 'folds' => 0, 'observations' => count($values), 'from' => array_key_first($values), 'to' => array_key_last($values), 'candidates' => [], 'reason' => $series->blocked];
        if ($series->blocked !== null || !in_array($horizon, [7, 14, 28], true)) {
            return $result;
        }
        $last = array_key_last($values);
        if ($last === null || self::days($last, $issued) > 7 || count($values) < 56) {
            $result['reason'] = 'Needs 56 observed days and a latest observation within seven days.';
            return $result;
        }
        if ($this->model($values, 'mean', $issued, $horizon) === null) {
            $result['reason'] = 'Recent training history has less than 90% observed coverage.';
            return $result;
        }
        $origins = [];
        // Target windows are disjoint. The issuance date is before every scored day.
        for ($back = $horizon; $back <= $horizon * 8; $back += $horizon) {
            $origin = self::date($last, -$back);
            $training = array_filter($values, static fn(string $d): bool => $d <= $origin, ARRAY_FILTER_USE_KEY);
            if ($this->model($training, 'mean', $origin, $horizon) === null) {
                continue;
            }
            $targets = [];
            for ($step = 1; $step <= $horizon; $step++) {
                $date = self::date($origin, $step);
                if (isset($values[$date])) {
                    $targets[$date] = $values[$date];
                }
            }
            if (count($targets) >= (int) ceil($horizon * .9)) {
                $origins[$origin] = $targets;
            }
        }
        $eligible = [];
        foreach (array_keys(self::METHODS) as $method) {
            if ($this->model($values, $method, $issued, $horizon, $calendar) === null) {
                continue;
            }
            $folds = [];
            foreach ($origins as $origin => $targets) {
                $training = array_filter($values, static fn(string $d): bool => $d <= $origin, ARRAY_FILTER_USE_KEY);
                $model = $this->model($training, $method, $origin, $horizon, $calendar);
                if ($method === 'calendar') {
                    foreach ($calendar as $event) {
                        if ($event->knownOn <= $origin && ($event->excludedOn === null || $event->excludedOn > $origin) && $event->date > $origin && $event->date <= self::date($origin, $horizon) && !isset($targets[$event->date])) {
                            $model = null;
                            break;
                        }
                    }
                }
                if ($model !== null) {
                    $folds[$origin] = $model;
                }
            }
            if (count($folds) >= 4) {
                $eligible[$method] = $folds;
            }
        }
        $common = array_keys($origins);
        foreach ($eligible as $folds) {
            $common = array_values(array_intersect($common, array_keys($folds)));
        }
        $selected = 'mean';
        if ($eligible !== [] && count($common) >= 4) {
            foreach ($eligible as $method => $folds) {
                $errors = [];
                foreach ($common as $origin) {
                    foreach ($origins[$origin] as $date => $actual) {
                        $errors[] = abs($folds[$origin][$date] - $actual);
                    }
                }
                $result['candidates'][$method] = array_sum($errors) / count($errors);
            }
            asort($result['candidates']);
            $baselines = array_intersect_key($result['candidates'], array_flip(['mean', 'linear', 'weekday']));
            $selected = array_key_first($baselines) ?? 'mean';
            $baselineError = $baselines[$selected] ?? 0.0;
            foreach (['annual', 'calendar'] as $extra) {
                if (isset($result['candidates'][$extra], $result['candidates'][$selected]) && $result['candidates'][$extra] <= $baselineError * .95 && $result['candidates'][$extra] < $result['candidates'][$selected]) {
                    $selected = $extra;
                }
            }
            $result['mae'] = $result['candidates'][$selected] ?? null;
            $result['folds'] = count($common);
        }
        $result['method'] = self::METHODS[$selected];
        $result['points'] = $this->model($values, $selected, $issued, $horizon, $calendar) ?? [];
        $result['reason'] = $result['folds'] === 0 ? 'Recent-average fallback; fewer than four comparable disjoint test windows.' : null;
        return $result;
    }

    /**
     * @param array<string, float> $values
     * @param list<ForecastEvent> $calendar
     * @return array<string, float>|null
     */
    private function model(array $values, string $method, string $origin, int $horizon, array $calendar = []): ?array
    {
        $last = array_key_last($values);
        if ($last === null || self::days($last, $origin) > 7) {
            return null;
        }
        $windowDays = in_array($method, ['weekday', 'calendar'], true) ? 56 : 28;
        $window = array_filter($values, static fn(string $d): bool => $d >= self::date($last, -$windowDays + 1), ARRAY_FILTER_USE_KEY);
        // Count missing leading dates too; only genuinely shorter initial history shortens a fold.
        $expected = min($windowDays, self::days((string) array_key_first($values), $last) + 1);
        if (count($window) < 14 || count($window) / $expected < .9) {
            return null;
        }
        $mean = array_sum($window) / count($window);
        $groups = array_fill(0, 7, []);
        $xs = [];
        foreach ($window as $date => $v) {
            $groups[(int) (new \DateTimeImmutable($date))->format('w')][] = $v;
            $xs[$date] = (float) self::days($last, $date);
        }
        if ($method === 'weekday' && min(array_map(count(...), $groups)) < 4) {
            return null;
        }
        if ($method === 'annual' && (count($values) < 366 || self::days((string) array_key_first($values), $last) < 365)) {
            return null;
        }
        $xMean = array_sum($xs) / count($xs);
        $numerator = $denominator = 0.0;
        foreach ($window as $date => $v) {
            $numerator += ($xs[$date] - $xMean) * ($v - $mean);
            $denominator += ($xs[$date] - $xMean) ** 2;
        }
        $slope = $denominator > 0 ? $numerator / $denominator : 0.0;
        $points = [];
        $eventDates = [];
        $eventValues = [];
        if ($method === 'calendar') {
            foreach ($calendar as $event) {
                if ($event->knownOn <= $origin && ($event->excludedOn === null || $event->excludedOn > $origin)) {
                    if (isset($eventDates[$event->date]) && $eventDates[$event->date] !== $event->name) {
                        return null;
                    }
                    $eventDates[$event->date] = $event->name;
                    if ($event->date <= $origin && isset($values[$event->date])) {
                        $eventValues[$event->name][] = $values[$event->date];
                    }
                }
            }
            $groups = array_fill(0, 7, []);
            foreach ($window as $date => $value) {
                if (!isset($eventDates[$date])) {
                    $groups[(int) (new \DateTimeImmutable($date))->format('w')][] = $value;
                }
            }
            $targets = array_filter($eventDates, static fn(string $date): bool => $date > $origin && $date <= self::date($origin, $horizon), ARRAY_FILTER_USE_KEY);
            if ($targets === [] || min(array_map(count(...), $groups)) < 4) {
                return null;
            }
            foreach ($targets as $name) {
                if (count($eventValues[$name] ?? []) < 2) {
                    return null;
                }
            }
        }
        for ($step = 1; $step <= $horizon; $step++) {
            $date = self::date($origin, $step);
            $prior = (new \DateTimeImmutable($date))->modify('-1 year')->format('Y-m-d');
            if ($method === 'annual' && (!isset($values[$prior]) || substr($prior, 5) !== substr($date, 5))) {
                return null; // Do not invent a leap-day or missing holiday analogue.
            }
            $weekday = (int) (new \DateTimeImmutable($date))->format('w');
            $points[$date] = max(0.0, match ($method) {
                'linear' => $mean - $slope * $xMean + $slope * self::days($last, $date),
                'weekday' => array_sum($groups[$weekday]) / count($groups[$weekday]),
                'annual' => $values[$prior],
                'calendar' => isset($eventDates[$date]) ? array_sum($eventValues[$eventDates[$date]]) / count($eventValues[$eventDates[$date]]) : array_sum($groups[$weekday]) / count($groups[$weekday]),
                default => $mean,
            });
        }
        return $points;
    }

    public static function date(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }
    public static function days(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%r%a');
    }
}
