<?php
/**
 * Search Console KPI tiles with the change against the previous period.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\SearchConsole\Metrics $totals
 * @var SeoControlCenter\Domain\SearchConsole\Metrics $previous
 * @var bool $hasPrevious
 * @var SeoControlCenter\Domain\Shared\DateRange $range
 * @var bool|null $showPosition false for sources without an overall position (Bing)
 * @var array<string, string>|null $notes small line under a tile, by its label (e.g. the Google/Bing split)
 */
$num = static fn(float|int $v): string => number_format((float) $v);
$pct = static fn(float $v): string => number_format($v * 100, 1) . ' %';

/** Signed change with direction text; $lowerIsBetter for position. */
$delta = static function (float $now, float $before, bool $asPoints = false, bool $lowerIsBetter = false) use ($view): string {
    if ($asPoints) {
        $diff = ($now - $before) * 100;
        $text = sprintf('%+.1f pts', $diff);
    } elseif ($lowerIsBetter) {
        $diff = $now - $before;
        $text = sprintf('%+.1f', $diff);
    } else {
        if ($before <= 0) {
            return '<span class="delta">no earlier data</span>';
        }
        $diff = ($now - $before) / $before;
        $text = sprintf('%+.1f %%', $diff * 100);
    }
    if (abs($diff) < 0.0005) {
        return '<span class="delta">no change</span>';
    }
    $good = $lowerIsBetter ? $diff < 0 : $diff > 0;

    // Arrow = direction of the value, colour = whether that is an improvement.
    return sprintf('<span class="delta %s">%s %s</span>', $good ? 'delta-good' : 'delta-bad', $diff > 0 ? '▲' : '▼', $view->e($text));
};
$tiles = [
    ['Clicks', $num($totals->clicks), $hasPrevious ? $delta($totals->clicks, $previous->clicks) : ''],
    ['Impressions', $num($totals->impressions), $hasPrevious ? $delta($totals->impressions, $previous->impressions) : ''],
    ['Average CTR', $pct($totals->ctr()), $hasPrevious ? $delta($totals->ctr(), $previous->ctr(), asPoints: true) : ''],
    ['Average position', number_format($totals->position, 1), $hasPrevious && $previous->impressions > 0 ? $delta($totals->position, $previous->position, lowerIsBetter: true) : ''],
];
if (($showPosition ?? true) === false) {
    array_pop($tiles);
}
?>
<div class="stat-grid">
    <?php foreach ($tiles as [$label, $value, $change]): ?>
        <section class="stat">
            <h2 class="stat-label"><?= $view->e($label) ?></h2>
            <p class="stat-value"><?= $view->e($value) ?></p>
            <?php if (isset($notes[$label])): ?><p class="stat-note"><?= $view->e($notes[$label]) ?></p><?php endif; ?>
            <?php if ($change !== ''): ?><p class="stat-delta"><?= $change ?> <span class="muted">vs previous <?= $view->e($range->days()) ?> days</span></p><?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>
