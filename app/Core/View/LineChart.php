<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\View;

/**
 * Geometry for a single-series line chart drawn as inline SVG
 * (no chart library, no inline script: works under the strict CSP).
 * Values start at a zero baseline; y ticks are rounded to 1/2/5 steps.
 * Inverted charts (rank positions) put 1 at the top and have no area fill.
 */
final readonly class LineChart
{
    /** Default drawing width; full-width cards use WIDE so labels are not scaled up. */
    public const int WIDTH = 720;
    public const int WIDE = 1080;
    public const int HEIGHT = 200;
    public const int LEFT = 52;
    public const int RIGHT = 12;
    public const int TOP = 12;
    public const int BOTTOM = 26;

    /**
     * @param list<array{x: float, y: float}> $points
     * @param list<array{y: float, value: float}> $ticks
     * @param list<array{x: float, label: string}> $xLabels
     */
    private function __construct(
        public array $points,
        public array $ticks,
        public array $xLabels,
        public string $linePath,
        public string $areaPath,
        public int $width = self::WIDTH,
    ) {}

    /**
     * @param list<array{0: string, 1: float}> $series [date, value], oldest first
     */
    public static function build(array $series, bool $invert = false, int $width = self::WIDTH): self
    {
        $n = count($series);
        $max = $n > 0 ? max(array_map(static fn(array $p): float => $p[1], $series)) : 0.0;
        // Positions: at least 1-10 on the axis, so steps are whole numbers.
        [$top, $step] = self::niceScale($invert ? max($max, 10.0) : $max);

        $plotW = $width - self::LEFT - self::RIGHT;
        $plotH = self::HEIGHT - self::TOP - self::BOTTOM;
        $baseline = self::TOP + $plotH;
        // Normal: 0 at the baseline, $top at the top. Inverted: 1 at the top, $top at the baseline.
        $yFor = static fn(float $value): float => $invert
            ? self::TOP + ($top > 1 ? $plotH * ($value - 1) / ($top - 1) : 0)
            : $baseline - ($top > 0 ? $plotH * $value / $top : 0);

        $points = [];
        foreach ($series as $i => [, $value]) {
            $x = self::LEFT + ($n > 1 ? $plotW * $i / ($n - 1) : $plotW / 2);
            $points[] = ['x' => round($x, 1), 'y' => round($yFor($value), 1)];
        }

        $ticks = [];
        for ($v = $invert ? $step : 0.0; $v <= $top + 1e-9; $v += $step) {
            $ticks[] = ['y' => round($yFor($v), 1), 'value' => $v];
        }
        if ($invert && ($ticks[0]['value'] ?? 0.0) > 1.0) {
            array_unshift($ticks, ['y' => round($yFor(1.0), 1), 'value' => 1.0]);
        }

        $xLabels = [];
        if ($n > 0) {
            foreach (array_unique([0, intdiv($n - 1, 2), $n - 1]) as $i) {
                $xLabels[] = ['x' => $points[$i]['x'], 'label' => self::shortDate($series[$i][0])];
            }
        }

        $line = '';
        foreach ($points as $i => $p) {
            $line .= ($i === 0 ? 'M' : 'L') . $p['x'] . ' ' . $p['y'];
        }
        $area = $points === [] || $invert ? '' : $line . 'L' . $points[$n - 1]['x'] . ' ' . $baseline . 'L' . $points[0]['x'] . ' ' . $baseline . 'Z';

        return new self($points, $ticks, $xLabels, $line, $area, $width);
    }

    /**
     * @return array{0: float, 1: float} [axis maximum, tick step] with 4-5 ticks
     */
    public static function niceScale(float $max): array
    {
        if ($max <= 0) {
            return [1.0, 1.0];
        }
        $rough = $max / 4;
        $magnitude = 10 ** floor(log10($rough));
        $step = $magnitude;
        foreach ([1, 2, 5, 10] as $m) {
            if ($m * $magnitude >= $rough) {
                $step = $m * $magnitude;
                break;
            }
        }

        return [ceil($max / $step) * $step, $step];
    }

    /**
     * Locate a calendar date on the existing point-index scale; metric values stay unchanged.
     * @param list<array{0: string, 1: float}> $series
     */
    public static function xForDate(array $series, string $date, int $width = self::WIDTH): ?float
    {
        $n = count($series);
        if ($n === 0 || $date < $series[0][0] || $date > $series[$n - 1][0]) {
            return null;
        }
        $plot = $width - self::LEFT - self::RIGHT;
        if ($n === 1) {
            return self::LEFT + $plot / 2;
        }
        $index = 0.0;
        foreach ($series as $i => [$day]) {
            if ($day === $date) {
                $index = (float) $i;
                break;
            }
            if ($day > $date && $i > 0) {
                $from = new \DateTimeImmutable($series[$i - 1][0]);
                $span = (int) $from->diff(new \DateTimeImmutable($day))->days;
                $index = $i - 1 + ($span === 0 ? 0 : (int) $from->diff(new \DateTimeImmutable($date))->days / $span);
                break;
            }
        }
        return round(self::LEFT + $plot * $index / ($n - 1), 1);
    }

    public static function compact(float $value): string
    {
        return match (true) {
            $value >= 1_000_000 => rtrim(rtrim(number_format($value / 1_000_000, 1), '0'), '.') . 'M',
            $value >= 10_000 => rtrim(rtrim(number_format($value / 1_000, 1), '0'), '.') . 'K',
            default => number_format($value),
        };
    }

    private static function shortDate(string $date): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $d === false ? $date : $d->format('j M Y');
    }
}
