<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Forecasting;

/** Forward residuals only; no retraining or backtest residuals enter calibration. */
final class ForecastCalibration
{
    /** @param list<array{issued: string, target: string, prediction: float, actual: float}> $scored
     * @return array{radius: ?float, sample: int, mae: ?float}
     */
    public function summarize(array $scored): array
    {
        usort($scored, static fn(array $a, array $b): int => strcmp($a['issued'], $b['issued']));
        $errors = [];
        $previousEnd = '';
        foreach ($scored as $record) {
            if ($record['issued'] < $previousEnd) {
                continue;
            }
            $errors[] = abs($record['prediction'] - $record['actual']);
            $previousEnd = $record['target'];
        }
        sort($errors);
        $n = count($errors);
        // Finite-sample 80% conformal rank; time dependence prevents a coverage guarantee.
        $rank = (int) ceil(($n + 1) * .8);
        return ['radius' => $n >= 20 && $rank <= $n ? $errors[$rank - 1] : null, 'sample' => $n, 'mae' => $n > 0 ? array_sum($errors) / $n : null];
    }
}
