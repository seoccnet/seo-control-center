<?php
/**
 * A daily line chart card with keyboard/hover read-out (assets/js/line-chart.js,
 * loaded once by the page).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var string $title
 * @var list<array{0: string, 1: float}> $series  [date, value] per day
 * @var SeoControlCenter\Domain\Shared\DateRange $range
 * @var string|null $link  optional "more" link (href)
 * @var string|null $linkLabel
 * @var bool|null $wide  full-width card (drawn wider so labels keep their size)
 * @var bool|null $invert  positions: 1 at the top
 * @var string|null $suffix  appended to values in the read-out (" %")
 */
use SeoControlCenter\Core\View\LineChart;

$chart = LineChart::build(array_map(static fn(array $point): array => [$point[0], (float) ($point[1] ?? 0)], $series), (bool) ($invert ?? false), ($wide ?? false) ? LineChart::WIDE : LineChart::WIDTH);
$formatValue = static fn(?float $value): string => $value === null ? 'Unavailable' : rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.') . ($suffix ?? '');
$values = array_values(array_filter(array_column($series, 1), static fn($value): bool => $value !== null));
$linePath = ''; $connected = false;
foreach ($chart->points as $i => $point) {
    if ($series[$i][1] === null) { $connected = false; continue; }
    $linePath .= ($connected ? ' L' : ' M') . $point['x'] . ',' . $point['y']; $connected = true;
}
?>
<figure class="card chart" data-chart>
    <figcaption class="card-header">
        <h2><?= $view->e($title) ?></h2>
        <?php if (($link ?? null) !== null): ?>
            <a class="button button-ghost button-small" href="<?= $view->e($link) ?>"><?= $view->e($linkLabel ?? 'Details') ?><?= $view->icon('arrow-right') ?></a>
        <?php endif; ?>
    </figcaption>
    <?php if ($values === []): ?><p class="muted">No stored measurements in this period. Import data or choose a period with results.</p><?php else: ?>
    <dl class="chart-summary">
        <div><dt>First displayed · <?= $view->e($series[0][0]) ?></dt><dd><?= $view->e($formatValue($series[0][1])) ?></dd></div>
        <div><dt>Latest displayed · <?= $view->e($series[count($series) - 1][0]) ?></dt><dd><?= $view->e($formatValue($series[count($series) - 1][1])) ?></dd></div>
        <div><dt><?= ($invert ?? false) ? 'Best displayed position' : 'Highest displayed value' ?></dt><dd><?= $view->e($formatValue(($invert ?? false) ? min($values) : max($values))) ?></dd></div>
    </dl>
    <div class="chart-plot">
        <svg viewBox="0 0 <?= $chart->width ?> <?= LineChart::HEIGHT ?>" role="img" aria-label="<?= $view->e($title . ', ' . $range->fromString() . ' to ' . $range->toString()) ?>"<?= ($suffix ?? null) !== null ? ' data-suffix="' . $view->e($suffix) . '"' : '' ?>
             data-points="<?= $view->e(json_encode(array_map(static fn(array $pt, array $s): array => [$pt['x'], $pt['y'], $s[0], $s[1]], $chart->points, $series), JSON_THROW_ON_ERROR)) ?>">
            <?php foreach ($chart->ticks as $tick): ?>
                <line class="grid" x1="<?= LineChart::LEFT ?>" x2="<?= $chart->width - LineChart::RIGHT ?>" y1="<?= $tick['y'] ?>" y2="<?= $tick['y'] ?>"/>
                <text class="tick" x="<?= LineChart::LEFT - 8 ?>" y="<?= $tick['y'] ?>" text-anchor="end" dominant-baseline="middle"><?= $view->e(($suffix ?? null) !== null ? rtrim(rtrim(number_format($tick['value'], 2, '.', ''), '0'), '.') . $suffix : LineChart::compact($tick['value'])) ?></text>
            <?php endforeach; ?>
            <?php foreach ($chart->xLabels as $i => $xl): ?>
                <text class="tick" x="<?= $xl['x'] ?>" y="<?= LineChart::HEIGHT - 6 ?>" text-anchor="<?= $i === 0 ? 'start' : ($i === count($chart->xLabels) - 1 ? 'end' : 'middle') ?>"><?= $view->e($xl['label']) ?></text>
            <?php endforeach; ?>
            <?php if (count($values) === count($series)): ?><path class="area" d="<?= $view->e($chart->areaPath) ?>"/><?php endif; ?>
            <path class="line" d="<?= $view->e($linePath) ?>"/>
            <?php foreach ($chart->points as $i => $point): ?><?php if ($series[$i][1] !== null && ($series[$i - 1][1] ?? null) === null && ($series[$i + 1][1] ?? null) === null): ?><circle class="recorded-point" cx="<?= $point['x'] ?>" cy="<?= $point['y'] ?>" r="3"/><?php endif; ?><?php endforeach; ?>
            <?php $markedDates = []; foreach (($annotations ?? []) as $annotation): ?>
                <?php $x = LineChart::xForDate($series, $annotation->date, $chart->width); if ($x === null || isset($markedDates[$annotation->date])) { continue; } $markedDates[$annotation->date] = true; ?>
                <line class="annotation-marker" x1="<?= $x ?>" x2="<?= $x ?>" y1="<?= LineChart::TOP ?>" y2="<?= LineChart::HEIGHT - LineChart::BOTTOM ?>"><title><?= $view->e('Recorded change · ' . $annotation->date . ' · see SEO change context below') ?></title></line>
            <?php endforeach; ?>
            <line class="crosshair" x1="0" x2="0" y1="<?= LineChart::TOP ?>" y2="<?= LineChart::HEIGHT - LineChart::BOTTOM ?>" hidden/>
            <circle class="marker" r="4" cx="0" cy="0" hidden/>
        </svg>
        <div class="chart-tooltip" role="status" hidden></div>
    </div>
    <details class="chart-data"><summary>View underlying data · <?= count($series) ?> data points</summary>
        <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Date</th><th scope="col"><?= $view->e($title) ?></th></tr></thead><tbody>
            <?php foreach ($series as [$date, $value]): ?><tr><td><?= $view->e($date) ?></td><td><?= $view->e($formatValue($value)) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </details>
    <?php endif; ?>
    <?php if (($annotations ?? []) !== [] && ($showAnnotationContext ?? true)): ?>
        <p class="muted small">Dashed markers show recorded change dates, not measured effects.</p>
        <?= $view->partial('partials/annotation-context', ['records' => array_map(static fn($a): array => $a->present(), $annotations), 'project' => $project]) ?>
    <?php endif; ?>
</figure>
