<?php
/** One metric at a time; all charts remain available without JavaScript. */
$chartMetrics = [['clicks', 'Clicks per day', 'Clicks'], ['impressions', 'Impressions per day', 'Impressions'], ['ctr', 'Click-through rate', 'CTR']];
if ($showPosition ?? true) $chartMetrics[] = ['position', 'Average position', 'Position'];
?>
<section id="performance-trends" class="insight-trends performance-section" data-insight-tabs aria-labelledby="performance-trends-title">
    <div class="section-header">
        <div><p class="portfolio-eyebrow">Daily activity</p><h2 id="performance-trends-title">Search trends</h2><p class="muted small">Follow visibility and traffic throughout the selected period.</p></div>
        <div class="insight-segments" data-insight-tablist hidden aria-label="Trend metric">
            <?php foreach ($chartMetrics as [$metric, $title, $label]): ?><button type="button" data-insight-tab="<?= $metric ?>"><span class="performance-dot performance-dot-<?= $metric ?>" aria-hidden="true"></span><?= $view->e($label) ?></button><?php endforeach; ?>
        </div>
    </div>
    <?php foreach ($chartMetrics as [$metric, $title, $label]): ?>
        <div class="performance-trend-panel performance-metric-<?= $metric ?>" data-insight-panel="<?= $metric ?>">
            <?= $view->partial('partials/line-chart', [
                'title' => $title,
                'series' => array_map(static fn(array $d): array => [$d[0], $d[1] === null || (in_array($metric, ['ctr', 'position'], true) && $d[1]->impressions === 0) ? null : (float) ($metric === 'ctr' ? $d[1]->ctr() * 100 : $d[1]->{$metric})], $data['days']),
                'range' => $range, 'annotations' => $annotations ?? [], 'project' => $project,
                'wide' => true, 'invert' => $metric === 'position', 'suffix' => $metric === 'ctr' ? ' %' : null,
                'showAnnotationContext' => false,
            ]) ?>
        </div>
    <?php endforeach; ?>
    <p class="muted small">Missing import days appear as gaps. CTR is a percentage.<?= ($showPosition ?? true) ? ' Lower position is better.' : '' ?> Hover or focus the chart and use the arrow keys to inspect each day.</p>
    <?php if (($annotations ?? []) !== []): ?>
        <details class="card insight-data"><summary>SEO change context · <?= count($annotations) ?> recorded changes</summary>
            <p class="muted small">Dashed markers show recorded change dates, not measured effects.</p>
            <?= $view->partial('partials/annotation-context', ['records' => array_map(static fn($a): array => $a->present(), $annotations), 'project' => $project]) ?>
        </details>
    <?php endif; ?>
</section>
