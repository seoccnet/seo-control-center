<?php
/** Where search traffic comes from: engines, GA4 organic visits, Google countries and devices. Saved imports only. */

use SeoControlCenter\Domain\International\CountryCodes;

$audience = $search['audience'];
$ga = $evidence['analytics'];
$google = $search['totals'];
$bingTotals = $search['bing']['totals'] ?? null;
$engines = array_values(array_filter([
    ['label' => 'Google', 'tone' => 'google', 'clicks' => $google->clicks, 'impressions' => $google->impressions],
    $bingTotals !== null ? ['label' => 'Bing', 'tone' => 'bing', 'clicks' => $bingTotals->clicks, 'impressions' => $bingTotals->impressions] : null,
]));
$engineClicks = array_sum(array_column($engines, 'clicks'));
$pct = static fn(float $share): string => number_format($share * 100, $share > 0 && $share < 0.01 ? 1 : 0) . ' %';
$change = static function (int $now, ?int $before): string {
    if ($before === null) {
        return '';
    }
    if ($before === 0) {
        return $now > 0 ? '<span class="overview-audience-delta delta-good">new</span>' : '';
    }
    $percent = ($now - $before) / $before * 100;
    $class = abs($percent) < 0.5 ? '' : ($percent > 0 ? ' delta-good' : ' delta-bad');

    return '<span class="overview-audience-delta' . $class . '" title="Clicks ' . number_format($before) . ' in the previous period">' . sprintf('%+.0f', $percent) . ' %</span>';
};
$devices = $audience['devices'];
$countries = $audience['countries'];
$deviceLabel = static fn(string $key): string => match (strtolower($key)) {
    'mobile' => 'Mobile',
    'desktop' => 'Desktop',
    'tablet' => 'Tablet',
    default => ucfirst(strtolower($key)),
};
$unit = static fn(array $split): string => $split['metric'] === 'clicks' ? 'clicks' : 'impressions';
?>
<section class="card overview-audience" aria-labelledby="overview-audience-heading">
    <div class="card-header"><div><p class="integration-kicker">Audience</p><h2 id="overview-audience-heading">Where your traffic comes from</h2></div><a class="small" href="<?= $view->e($base . '/performance?range=' . $rangeKey) ?>">Performance →</a></div>
    <p class="muted small"><?= $view->e($search['range']->fromString()) ?> – <?= $view->e($search['range']->toString()) ?><?= $audience['comparable'] ? ' · change vs previous period' : '' ?></p>

    <div class="overview-audience-block">
        <h3 class="overview-subheading">Search engines</h3>
        <?php if ($engineClicks > 0): ?>
        <svg class="overview-share-bar" viewBox="0 0 100 10" preserveAspectRatio="none" role="img" aria-label="<?= $view->e(implode(', ', array_map(static fn(array $e): string => $e['label'] . ' ' . $pct($e['clicks'] / $engineClicks) . ' of clicks', $engines))) ?>"><?php $x = 0.0; foreach ($engines as $engine): $w = $engine['clicks'] / $engineClicks * 100; ?><rect class="tone-<?= $view->e($engine['tone']) ?>" x="<?= round($x, 3) ?>" y="0" width="<?= round($w, 3) ?>" height="10"/><?php $x += $w; endforeach; ?></svg>
        <?php endif; ?>
        <ul class="overview-legend-list">
            <?php foreach ($engines as $engine): ?>
            <li><span class="overview-swatch tone-<?= $view->e($engine['tone']) ?>" aria-hidden="true"></span><span class="overview-legend-label"><?= $view->e($engine['label']) ?></span><strong><?= number_format($engine['clicks']) ?></strong><span class="muted small">clicks<?= $engineClicks > 0 ? ' · ' . $pct($engine['clicks'] / $engineClicks) : '' ?></span></li>
            <?php endforeach; ?>
            <?php if ($bingTotals === null): ?><li class="is-missing"><span class="overview-swatch tone-bing" aria-hidden="true"></span><span class="overview-legend-label">Bing</span><a class="small" href="<?= $view->e($base) ?>/bing">Connect Bing →</a></li><?php endif; ?>
        </ul>
        <?php if ($ga['totals'] !== null): ?>
        <p class="overview-audience-ga"><span class="overview-swatch tone-ga" aria-hidden="true"></span><span><strong><?= number_format($ga['totals']['sessions']) ?></strong> GA4 organic sessions<?= $ga['previous'] !== null ? ' ' . $change($ga['totals']['sessions'], $ga['previous']['sessions']) : '' ?> · <strong><?= number_format($ga['totals']['conversions'], $ga['totals']['conversions'] === floor($ga['totals']['conversions']) ? 0 : 1) ?></strong> key events</span><a class="small" href="<?= $view->e($base) ?>/analytics">GA4 →</a></p>
        <?php else: ?>
        <p class="overview-audience-ga is-missing"><span class="overview-swatch tone-ga" aria-hidden="true"></span><span class="muted small">GA4 visits and key events appear here once a property is imported.</span><a class="small" href="<?= $view->e($base) ?>/analytics"><?= $ga['propertyId'] === null ? 'Connect GA4' : 'Review GA4' ?> →</a></p>
        <?php endif; ?>
    </div>

    <div class="overview-audience-block">
        <h3 class="overview-subheading">Top countries <span class="muted small">Google, by <?= $unit($countries) ?></span></h3>
        <?php if ($countries['rows'] === []): ?>
        <p class="muted small">No country breakdown imported for these dates. Search Console imports add it per day.</p>
        <?php else: $max = max(0.0001, ...array_column($countries['rows'], 'share')); ?>
        <ol class="overview-country-list">
            <?php foreach ($countries['rows'] as $row): $m = $row['metrics']; ?>
            <li>
                <span class="overview-country-name" title="<?= $view->e(strtoupper($row['key'])) ?>"><?= $view->e(CountryCodes::name($row['key'])) ?></span>
                <span class="overview-country-value"><strong><?= number_format($countries['metric'] === 'clicks' ? $m->clicks : $m->impressions) ?></strong> <?= $countries['metric'] === 'clicks' ? $change($m->clicks, $row['previousClicks']) : '' ?></span>
                <span class="overview-country-share muted small"><?= $pct($row['share']) ?></span>
                <svg class="overview-country-bar" viewBox="0 0 100 4" preserveAspectRatio="none" aria-hidden="true" focusable="false"><rect class="track" x="0" y="0" width="100" height="4"/><rect class="tone-google" x="0" y="0" width="<?= round($row['share'] / $max * 100, 3) ?>" height="4"/></svg>
            </li>
            <?php endforeach; ?>
            <?php if ($countries['other']['count'] > 0): ?><li class="is-other"><span class="overview-country-name muted"><?= $countries['other']['count'] ?> other <?= $countries['other']['count'] === 1 ? 'country' : 'countries' ?></span><span class="overview-country-value"><?= number_format($countries['metric'] === 'clicks' ? $countries['other']['clicks'] : $countries['other']['impressions']) ?></span><span class="overview-country-share muted small"><?= $pct($countries['other']['share']) ?></span></li><?php endif; ?>
        </ol>
        <?php endif; ?>
    </div>

    <div class="overview-audience-block">
        <h3 class="overview-subheading">Devices <span class="muted small">Google, by <?= $unit($devices) ?></span></h3>
        <?php if ($devices['rows'] === []): ?>
        <p class="muted small">No device breakdown imported for these dates.</p>
        <?php else: ?>
        <svg class="overview-share-bar" viewBox="0 0 100 10" preserveAspectRatio="none" role="img" aria-label="<?= $view->e(implode(', ', array_map(static fn(array $r): string => $deviceLabel($r['key']) . ' ' . $pct($r['share']), $devices['rows']))) ?>"><?php $x = 0.0; foreach ($devices['rows'] as $row): $w = $row['share'] * 100; ?><rect class="tone-device-<?= $view->e(strtolower($row['key'])) ?>" x="<?= round($x, 3) ?>" y="0" width="<?= round($w, 3) ?>" height="10"/><?php $x += $w; endforeach; ?></svg>
        <ul class="overview-legend-list overview-device-list">
            <?php foreach ($devices['rows'] as $row): $m = $row['metrics']; ?>
            <li><span class="overview-swatch tone-device-<?= $view->e(strtolower($row['key'])) ?>" aria-hidden="true"></span><span class="overview-legend-label"><?= $view->e($deviceLabel($row['key'])) ?></span><strong><?= $pct($row['share']) ?></strong><span class="muted small"><?= number_format($m->clicks) ?> clicks · CTR <?= $m->impressions > 0 ? number_format($m->ctr() * 100, 1) . ' %' : '—' ?> · pos. <?= $m->impressions > 0 ? number_format($m->position, 1) : '—' ?></span></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <details class="overview-about"><summary><?= $view->icon('info') ?>About this data</summary><p>Engine clicks come from Google Search Console and Bing Webmaster Tools for the same dates; Bing reports weekly, so its days follow its last imported week. GA4 counts organic-search sessions on landing pages and is a separate measurement: sessions and clicks never match exactly. Countries and devices are Google only, because Bing and the GA4 import do not provide them. Changes are shown only when both periods were imported completely.</p></details>
</section>
