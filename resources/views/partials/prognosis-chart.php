<?php
/** Recorded daily metrics; projections are calculated in the browser. */
$history = $search['forecast']['history'] ?? [];
$daily = $search['forecast']['display'] ?? [];
$preliminary = $search['forecast']['preliminary'] ?? [];
?>
<section class="card prognosis-card" aria-labelledby="prognosis-heading" data-prognosis data-days="<?= $view->e(json_encode($daily, JSON_THROW_ON_ERROR)) ?>" data-history="<?= $view->e(json_encode($history, JSON_THROW_ON_ERROR)) ?>" data-preliminary="<?= $view->e(json_encode($preliminary, JSON_THROW_ON_ERROR)) ?>" data-as-of="<?= $view->e($search['forecast']['asOf'] ?? '') ?>">
    <div class="card-header">
        <div><p class="integration-kicker">Trends & outlook</p><h2 id="prognosis-heading">Performance prognosis</h2></div>
        <label class="forecast-horizon">Forecast horizon <select data-horizon aria-label="Forecast horizon"><option value="7">Next 7 days</option><option value="14" selected>Next 14 days</option><option value="28">Next 28 days</option></select></label>
    </div>
    <p class="muted small prognosis-intro">Google Search Console daily metrics · <a href="<?= $view->e($base) ?>/forecasts">Save forecasts and review actual outcomes →</a></p>
    <?php if ($daily === []): ?>
        <div class="prognosis-empty"><span class="project-avatar" aria-hidden="true"><?= $view->icon('pulse') ?></span><h3>Your outlook starts with history</h3><p class="muted"><?= $bing !== null ? 'Connect Google Search Console to compare all four metrics. Bing performance remains available above.' : 'Link Search Console and import performance data to see trends and a forecast.' ?></p><a class="button button-small" href="<?= $view->e($base) ?>/search-console">Manage Search Console</a></div>
    <?php else: ?>
        <div class="prognosis-controls" role="group" aria-label="Chart metric">
            <button type="button" class="chip" data-metric="all" aria-pressed="true">All metrics</button>
            <button type="button" class="chip metric-clicks" data-metric="clicks" aria-pressed="false">Clicks</button>
            <button type="button" class="chip metric-impressions" data-metric="impressions" aria-pressed="false">Impressions</button>
            <button type="button" class="chip metric-ctr" data-metric="ctr" aria-pressed="false">CTR</button>
            <button type="button" class="chip metric-position" data-metric="position" aria-pressed="false">Position</button>
        </div>
        <div class="prognosis-controls prognosis-smoothing" role="group" aria-label="Line values">
            <button type="button" class="chip" data-smooth="1" aria-pressed="true">Daily</button>
            <button type="button" class="chip" data-smooth="7" aria-pressed="false">7-day average</button>
        </div>
        <p class="prognosis-summary" data-forecast-summary aria-live="polite" hidden></p>
        <div class="prognosis-canvas" hidden data-forecast-canvas>
            <svg viewBox="0 0 1000 300" role="img" aria-labelledby="forecast-svg-title forecast-svg-description" tabindex="0" data-forecast-svg>
                <title id="forecast-svg-title">Search performance and trend projection</title>
                <desc id="forecast-svg-description">Solid lines show recorded metrics. Dashed lines show estimates. Use left and right arrow keys to inspect dates.</desc>
                <?php foreach (array_unique(array_map(static fn($a): string => $a->date, $annotations ?? [])) as $date): ?><line class="annotation-marker" data-change-date="<?= $view->e($date) ?>" x1="64" x2="64" y1="18" y2="248" hidden><title><?= $view->e('Recorded change · ' . $date . ' · see SEO change context below') ?></title></line><?php endforeach; ?>
            </svg>
            <div class="forecast-legend" aria-hidden="true"><span><i></i>Recorded</span><span><i class="forecast-legend-dashed"></i>Projected trend</span><?php if (array_filter($preliminary, static fn(array $p): bool => $p['complete']) !== []): ?><span><b class="forecast-legend-preliminary"></b>Latest days, not final yet</span><?php endif; ?><span>Position: drawn upside down, higher is better</span></div>
            <p class="forecast-readout muted small" data-forecast-readout aria-live="polite"></p>
        </div>
        <div class="forecast-estimates" data-forecast-estimates aria-label="Projected daily metrics"></div>
        <p class="muted small forecast-method" data-forecast-method>Forecast needs sufficient recent date coverage. Missing days are not treated as zero.</p>
        <details class="chart-data forecast-details"><summary>Forecast details: validation, compared models &amp; method</summary>
            <details class="chart-data"><summary>Forecast validation & input tests</summary>
            <div class="table-scroll"><table class="table table-dense" data-no-table-controls><caption class="muted small">Forecast validation · rolling historical tests at the selected horizon</caption><thead><tr><th scope="col">Metric / method</th><th scope="col" class="num">Mean absolute error</th><th scope="col">Evidence</th></tr></thead><tbody data-forecast-validation></tbody></table></div>
            <p class="muted small" data-forecast-input-test></p>
        </details>
            <details class="chart-data"><summary>Compared forecast models</summary><div data-forecast-models></div></details>
            <details class="chart-data"><summary>Recorded data & forecast method</summary><p class="muted small">Last-value, linear, average and weekday models compete on shared historical windows. At least four weekly test origins are required for model selection; otherwise a recent-average fallback is explicitly unvalidated. Counts remain non-negative, CTR is 0–100% and position is at least 1. Clicks use predicted impressions × CTR only when the full pipeline lowers historical error by at least 5% against the linear-click baseline; otherwise that baseline is retained. Short-history compound forecasts are unvalidated. Position- or impression-assisted CTR is adopted only after at least 5% lower error on four shared test origins. Displayed ranges use the 80th percentile of absolute endpoint errors from at least eight origins; they are historical error envelopes, not calibrated probability intervals. Overlapping tests are not independent. No model assumes an uplift from planned work, audits or backlinks. In All metrics each line has its own relative scale. Position is drawn upside down, so higher is better on every line.</p><div class="table-scroll"><table class="table table-dense"><thead><tr><th>Date</th><th class="num">Clicks</th><th class="num">Impressions</th><th class="num">CTR</th><th class="num">Position</th></tr></thead><tbody><?php foreach ($daily as $day): ?><tr><td><?= $view->e($day['date']) ?></td><td class="num"><?= number_format($day['clicks']) ?></td><td class="num"><?= number_format($day['impressions']) ?></td><td class="num"><?= number_format($day['ctr'], 2) ?>%</td><td class="num"><?= $day['position'] === null ? '—' : number_format($day['position'], 1) ?></td></tr><?php endforeach; ?></tbody></table></div></details>
        </details>
        <?php if (($annotations ?? []) !== []): ?><?= $view->partial('partials/annotation-context', ['records' => array_map(static fn($a): array => $a->present(), $annotations), 'project' => $project]) ?><?php endif; ?>
        <details class="overview-about"><summary><?= $view->icon('info') ?>About this data</summary><p>Forecast training uses up to 180 stored days independently of the displayed range.<?php if ($preliminary !== []): ?> The dotted continuation of each line shows Google's preliminary numbers for the days after the last final day, so you can see whether they follow the projection; they are not used for training and change until Google finalizes them. A day Google is still collecting is not drawn; its partial numbers appear in the readout below the chart.<?php endif; ?> Bing remains in the search summary; it is excluded here to avoid mixing incomplete engine histories.</p></details>
        <script type="module" src="/assets/js/prognosis.js?v=<?= $view->e($assetVersion) ?>"></script>
    <?php endif; ?>
</section>
