<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Measurement;

/**
 * What worked on this site (0.45.0): the measured results of a project's SEO
 * changes, grouped by kind of change or by action plan. Only results read
 * behind every readiness gate count as measured (improved, declined or no
 * clear change, and goals reached or not); insufficient evidence and open
 * plans are counted apart, never as zero. Effects are better-is-positive, as
 * MeasurementResult gives them: percent for traffic metrics, positions for
 * average position. A controlled result compares with control pages; the
 * rest are associations.
 *
 * @phpstan-type Row array{group: string, label: string, status: string, effect: ?float, unit: string, controlled: bool}
 * @phpstan-type Summary array{group: string, label: string, changes: int, measured: int, improved: int, declined: int, unchanged: int, insufficient: int, open: int, controlled: int, median_percent: ?float, median_positions: ?float}
 */
final class ResultsLedger
{
    /** Outcome of each MeasurementResult status in the ledger. */
    public const array OUTCOMES = [
        'improved' => 'improved',
        'reached' => 'improved',
        'declined' => 'declined',
        'not_reached' => 'declined',
        'no_clear_change' => 'unchanged',
        'insufficient_evidence' => 'insufficient',
        'planned' => 'open',
        'pending' => 'open',
    ];

    /** Groups with fewer measured changes than this say so instead of reading as a pattern. */
    public const int FEW = 3;

    public static function outcome(string $status): string
    {
        return self::OUTCOMES[$status] ?? 'open';
    }

    /**
     * One summary per group, most measured first.
     *
     * @param list<Row> $rows
     * @return list<Summary>
     */
    public static function summarize(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $g = $groups[$row['group']] ?? ['group' => $row['group'], 'label' => $row['label'], 'changes' => 0, 'measured' => 0, 'improved' => 0, 'declined' => 0, 'unchanged' => 0, 'insufficient' => 0, 'open' => 0, 'controlled' => 0, 'percent' => [], 'positions' => []];
            ++$g['changes'];
            $outcome = self::outcome($row['status']);
            ++$g[$outcome];
            if (in_array($outcome, ['improved', 'declined', 'unchanged'], true)) {
                ++$g['measured'];
                if ($row['controlled']) {
                    ++$g['controlled'];
                }
                if ($row['effect'] !== null) {
                    $g[$row['unit'] === 'positions' ? 'positions' : 'percent'][] = $row['effect'];
                }
            }
            $groups[$row['group']] = $g;
        }
        $out = [];
        foreach ($groups as $g) {
            $out[] = [
                'group' => $g['group'], 'label' => $g['label'], 'changes' => $g['changes'], 'measured' => $g['measured'],
                'improved' => $g['improved'], 'declined' => $g['declined'], 'unchanged' => $g['unchanged'], 'insufficient' => $g['insufficient'], 'open' => $g['open'], 'controlled' => $g['controlled'],
                'median_percent' => self::median($g['percent']), 'median_positions' => self::median($g['positions']),
            ];
        }
        usort($out, static fn(array $a, array $b): int => [$b['measured'], $b['changes'], $a['label']] <=> [$a['measured'], $a['changes'], $b['label']]);

        return $out;
    }

    /**
     * One plain sentence for a group, for action plans: "On your site: 3 of 4
     * measured changes of this kind improved (median +12 %)." Null when no
     * change of the group has been measured yet.
     *
     * @param Summary $summary
     */
    public static function sentence(array $summary): ?string
    {
        if ($summary['measured'] === 0) {
            return null;
        }
        $median = $summary['median_percent'] !== null ? sprintf(', median %+.0f %%', $summary['median_percent']) : ($summary['median_positions'] !== null ? sprintf(', median %+.1f positions', $summary['median_positions']) : '');

        return sprintf(
            'On your site: %d of %d measured change%s improved%s%s%s.',
            $summary['improved'],
            $summary['measured'],
            $summary['measured'] === 1 ? '' : 's',
            $summary['declined'] > 0 ? sprintf(', %d declined', $summary['declined']) : '',
            $median,
            $summary['measured'] < self::FEW ? '; too few to show a pattern yet' : '',
        );
    }

    /** @param list<float> $values */
    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return round($n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2, 1);
    }
}
