<?php
$num = static fn(float|int $v): string => number_format((float) $v);
$ga = $evidence['analytics'];
$source = $search !== null ? ($search['bing'] !== null ? 'Google + Bing' : 'Google Search Console') : ($bing !== null ? 'Bing' : null);
$data = $search ?? $bing;
$totals = $search['combined'] ?? $bing['totals'] ?? null;
$previous = $search['combinedPrevious'] ?? $bing['previous'] ?? null;
// Bing reports no row on days without data, so its earlier period is often
// incomplete. Then the change is compared on Google's figures and labelled.
$googleOnly = $search !== null && $search['bing'] !== null && !$search['bing']['hasPrevious'];
$canCompare = $data !== null && $data['hasPrevious'];
$now = $googleOnly ? $search['totals'] : $totals;
$then = $googleOnly ? $search['previous'] : $previous;
// Daily shape of the displayed period for each search tile; position is drawn inverted (lower is better).
$daily = $search['daily'] ?? [];
$spark = static function (string $metric) use ($daily): string {
    $values = [];
    foreach ($daily as $day) {
        if ($day[$metric] !== null) {
            $values[] = (float) $day[$metric] * ($metric === 'position' ? -1 : 1);
        }
    }
    if (count($values) < 2) {
        return '';
    }
    [$min, $max] = [min($values), max($values)];
    $span = $max - $min > 0 ? $max - $min : 1.0;
    $step = 100 / (count($values) - 1);
    $points = [];
    foreach ($values as $i => $value) {
        $points[] = round($i * $step, 2) . ',' . round(22 - ($value - $min) / $span * 20, 2);
    }

    return '<svg class="kpi-spark" viewBox="0 0 100 24" preserveAspectRatio="none" aria-hidden="true" focusable="false"><polyline points="' . implode(' ', $points) . '" fill="none" stroke="currentColor" stroke-width="1.6" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"/></svg>';
};
?>
<?php if ($totals !== null || $ga['totals'] !== null): ?>
<div class="stat-grid overview-search-summary<?= $totals !== null && $ga['totals'] !== null ? ' has-outcomes' : '' ?>" aria-label="Search and outcome summary">
<?php if ($totals !== null): ?>
<?php foreach ([['Clicks', $totals->clicks, $now->clicks, $then->clicks, false, 'clicks'], ['Impressions', $totals->impressions, $now->impressions, $then->impressions, false, 'impressions'], ['CTR', $totals->impressions > 0 ? $totals->ctr() * 100 : null, $now->impressions > 0 ? $now->ctr() * 100 : null, $then->impressions > 0 ? $then->ctr() * 100 : null, true, 'ctr'], ['Average position', $search !== null && $search['totals']->impressions > 0 ? $search['totals']->position : null, $search !== null && $search['totals']->impressions > 0 ? $search['totals']->position : null, $search !== null && $search['previous']->impressions > 0 ? $search['previous']->position : null, true, 'position']] as [$label, $value, $current, $before, $decimal, $metric]): ?>
    <section class="stat"><h3 class="stat-label"><?= $view->e($label) ?></h3><p class="stat-value"><?= $value === null ? '—' : $view->e($decimal ? number_format($value, 1) . ($label === 'CTR' ? ' %' : '') : $num($value)) ?></p>
    <p class="stat-note"><?= $label === 'Average position' ? 'Google · impression-weighted' : $view->e($source) ?></p>
    <?php if ($canCompare && $current !== null && $before !== null): $diff = $current - $before; ?>
        <p class="stat-delta"><span class="delta<?= $diff == 0 ? '' : (($label === 'Average position' ? $diff < 0 : $diff > 0) ? ' delta-good' : ' delta-bad') ?>"><?= $view->e($decimal ? sprintf('%+.1f%s', $diff, $label === 'CTR' ? ' pp' : ' positions') : sprintf('%+d', $diff)) ?></span><?php if (!$decimal && $before > 0): ?> <span class="muted"><?= $view->e(sprintf('(%+.1f %%)', $diff / $before * 100)) ?></span><?php endif; ?><span class="muted small"> vs previous period<?= $googleOnly && $label !== 'Average position' ? ' · Google only' : '' ?></span></p>
    <?php else: ?><p class="stat-note">Comparison unavailable</p><?php endif; ?>
    <?= $spark($metric) ?>
    </section>
<?php endforeach; ?>
<?php endif; ?>
<?php if ($ga['totals'] !== null): ?>
<?php foreach (['sessions' => 'Sessions', 'conversions' => 'Key events', 'revenue' => 'Purchase revenue'] as $key => $label): $value = $ga['totals'][$key]; ?>
    <section class="stat overview-outcome-tile"><h3 class="stat-label"><?= $view->e($label) ?></h3><p class="stat-value"><?= $view->e(number_format($value, $key === 'sessions' ? 0 : 2)) ?></p>
    <p class="stat-note">GA4 · Organic Search<?= $key === 'revenue' ? ' · ' . $view->e($ga['metadata']['currency'] ?? '') : '' ?></p>
    <?php if ($ga['comparable']): $diff = $value - $ga['previous'][$key]; ?><p class="stat-delta"><span class="delta<?= $diff == 0 ? '' : ($diff > 0 ? ' delta-good' : ' delta-bad') ?>"><?= $view->e($key === 'sessions' ? sprintf('%+.0f', $diff) : sprintf('%+.2f', $diff)) ?></span><span class="muted small"> vs previous period</span></p>
    <?php else: ?><p class="stat-note">No comparable earlier period</p><?php endif; ?>
    </section>
<?php endforeach; ?>
<?php endif; ?>
</div>
<div class="overview-coverage muted small">
    <?php if ($search !== null): ?><span>Query/page imports: <?= $evidence['covered'] ?> / <?= $evidence['range']->days() ?> days; previous <?= $evidence['previousCovered'] ?> / <?= $evidence['range']->days() ?>.<?php if ($search['bing'] !== null): ?> Clicks: Google <?= $num($search['totals']->clicks) ?> · Bing <?= $num($search['bing']['totals']->clicks) ?>.<?php endif; ?></span><?php endif; ?>
    <?php if ($ga['totals'] !== null): ?><span>GA4: <?= $ga['covered'] ?> / <?= $evidence['range']->days() ?> imported days · <?= $view->e($ga['metadata']['timezone'] ?? '') ?><?= $ga['comparable'] ? '' : ' · comparison unavailable: both periods need complete imports, matching currency/timezone and no quality restrictions' ?>.</span> <a href="<?= $view->e($base) ?>/analytics">Analytics →</a><?php endif; ?>
</div>
<?php if ($ga['totals'] !== null && ($ga['metadata']['limited'] || $ga['metadata']['thresholded'])): ?><p class="notice notice-warning">Google marked this report limited or thresholded. Outcomes may be incomplete.</p><?php endif; ?>
<?php endif; ?>
