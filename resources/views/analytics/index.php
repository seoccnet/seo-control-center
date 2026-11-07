<?php

declare(strict_types=1);

$view->extend('layouts/app', ['title' => 'Analytics · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'analytics']);
$base = '/projects/' . $project->publicId . '/analytics';
?>
<div class="insight-workspace analytics-workspace">
<header class="insight-intro analytics-intro"><div><p class="portfolio-eyebrow">Organic search outcomes</p><h2>Organic search analytics</h2><p class="muted">Explore the sessions, key events and revenue recorded for your landing pages.</p></div><nav class="insight-segments" aria-label="Analytics sections"><a href="#analytics-summary">Overview</a><a href="#analytics-outcomes">Top pages</a><a href="#analytics-pages">Page results</a><?php if (isset($measurement)): ?><a href="#business-measurement">Business events</a><?php endif; ?><a href="#analytics-quality">Data quality</a><?php if ($canManage): ?><a href="#analytics-connection">Property & history</a><?php endif; ?></nav></header>

<section class="card insight-controls analytics-period"><div class="section-header"><div><p class="portfolio-eyebrow">Reporting period</p><h2>Choose your dates</h2></div><span class="badge">GA4 · Organic Search</span></div>

<form class="form range-form" method="get" action="<?= $view->e($base) ?>">
    <div class="form-grid"><div class="field"><label for="analytics-from">From</label><input type="date" name="from" id="analytics-from" value="<?= $view->e($range->fromString()) ?>" required></div><div class="field"><label for="analytics-to">To</label><input type="date" name="to" id="analytics-to" value="<?= $view->e($range->toString()) ?>" required></div></div>
    <button class="button button-small" type="submit">Compare dates</button>
</form>
<?php $comparable = $covered === $range->days() && $previousCovered === $previous->days() && !($metadata['limited'] ?? false) && !($metadata['thresholded'] ?? false) && !($previousMetadata['limited'] ?? false) && !($previousMetadata['thresholded'] ?? false); ?>
</section>
<?php $listedSessions = array_sum(array_column($rows,'sessions')); $listedEvents = array_sum(array_column($rows,'conversions')); $listedRevenue = array_sum(array_column($rows,'revenue')); ?>
<section id="analytics-summary" class="analytics-section" aria-labelledby="analytics-summary-title"><div class="section-header"><div><p class="portfolio-eyebrow">Period overview</p><h2 id="analytics-summary-title">Traffic, actions &amp; revenue</h2><p class="muted small">Results from the listed organic-search landing pages.</p></div></div>
<?php if (!$comparable): ?><p class="notice notice-warning" role="status">Comparison changes are unavailable while dates are missing or Google's report is limited or privacy-thresholded. Import more history below. Missing data does not mean zero.</p><?php endif; ?>
<div class="insight-metric-grid" aria-label="Listed landing-page results">
<?php foreach ([['Sessions',number_format($listedSessions),'visits attributed to Organic Search'],['Conversions',number_format($listedEvents,2),'recorded key events'],['Purchase revenue',number_format($listedRevenue,2) . ' ' . ($metadata['currency'] ?? ''),'in the reported currency'],['Landing pages',number_format(count($rows)),'included in this report']] as [$label,$value,$note]): ?><section class="card insight-metric"><p class="portfolio-eyebrow"><?= $view->e($label) ?></p><strong><?= $covered > 0 ? $view->e($value) : '—' ?></strong><p class="muted small"><?= $view->e($note) ?></p></section><?php endforeach; ?>
</div><p class="muted small">Summary covers the listed top 100 landing pages, not the entire property. Missing dates and report limits can reduce coverage.</p>
</section>
<section id="analytics-outcomes" class="analytics-section" data-insight-tabs aria-labelledby="analytics-outcomes-title">
    <div class="section-header"><div><p class="portfolio-eyebrow">Traffic destinations</p><h2 id="analytics-outcomes-title">Your leading landing pages</h2><p class="muted small">Compare the five leading pages for each metric among the listed top 100.</p></div>
        <div class="insight-segments" data-insight-tablist hidden aria-label="Landing page metric">
            <?php foreach ([['sessions', 'Sessions'], ['conversions', 'Conversions'], ['revenue', 'Revenue']] as [$metric, $label]): ?><button type="button" data-insight-tab="<?= $metric ?>"><span class="analytics-dot analytics-color-<?= $metric ?>" aria-hidden="true"></span><?= $view->e($label) ?></button><?php endforeach; ?>
        </div>
    </div>
    <?php foreach ([['sessions', 'Leading pages by sessions'], ['conversions', 'Leading pages by conversions'], ['revenue', 'Leading pages by purchase revenue']] as [$metric, $title]):
        $rankedRows = $rows;
        usort($rankedRows, static fn($a, $b): int => ($b[$metric] <=> $a[$metric]) ?: strcmp($a['url'], $b['url']));
        $bars = array_map(static fn($row): array => [
            'label' => $row['url'], 'value' => $row[$metric],
            'display' => number_format($row[$metric], $metric === 'sessions' ? 0 : 2) . ($metric === 'revenue' ? ' ' . ($metadata['currency'] ?? '') : ''),
        ], array_slice($rankedRows, 0, 5));
    ?>
        <div class="analytics-chart-panel analytics-color-<?= $metric ?>" data-insight-panel="<?= $metric ?>">
            <?= $view->partial('partials/insight-bars', ['title' => $title, 'eyebrow' => 'Page ranking', 'note' => 'Bars compare the listed values. Each metric ranks pages independently.', 'bars' => $bars]) ?>
        </div>
    <?php endforeach; ?>
</section>
<section class="card analytics-results" id="analytics-pages"><div class="card-header"><div><p class="portfolio-eyebrow">Page-level results</p><h2>Landing page performance</h2></div><span class="badge"><?= count($rows) ?> pages</span></div>
<p class="muted small">Top 100 landing pages by purchase revenue, then conversions. Select a URL to explore its details.</p>
<div class="table-scroll"><table class="table table-dense numeric"><thead><tr><th scope="col">Landing URL</th><th scope="col">Sessions</th><th scope="col">Conversions</th><th scope="col">Purchase revenue</th><th scope="col">Change vs previous period</th></tr></thead><tbody>
<?php foreach ($rows as $row): $before = $previousByUrl[$row['url']] ?? ['sessions' => 0, 'conversions' => 0.0, 'revenue' => 0.0]; ?><tr><td><a href="<?= $view->e('/projects/' . $project->publicId . '/pages/details?url=' . rawurlencode($row['url'])) ?>"><?= $view->e($row['url']) ?></a></td><td><?= $view->e(number_format($row['sessions'])) ?></td><td><?= $view->e(number_format($row['conversions'], 2)) ?></td><td><?= $view->e(number_format($row['revenue'], 2) . ' ' . ($metadata['currency'] ?? '')) ?></td><td><?php if ($comparable): ?><span class="small"><?= $view->e(sprintf('%+d sessions · %+.2f conversions · %+.2f %s', $row['sessions'] - $before['sessions'], $row['conversions'] - $before['conversions'], $row['revenue'] - $before['revenue'], $metadata['currency'] ?? '')) ?></span><?php else: ?><span class="muted">Unavailable</span><?php endif; ?></td></tr><?php endforeach; ?>
<?php if ($rows === []): ?><tr><td colspan="5">No imported organic landing-page data for these dates.</td></tr><?php endif; ?></tbody></table></div>
</section>
<?php if (isset($measurement)): ?><?= $view->partial('analytics/business', ['project' => $project, 'measurement' => $measurement, 'canEditProject' => $canEditProject ?? false, 'canManage' => $canManage]) ?><?php endif; ?>
<section id="analytics-quality" class="analytics-section" aria-labelledby="analytics-quality-title">
    <div class="section-header"><div><p class="portfolio-eyebrow">Data quality</p><h2 id="analytics-quality-title">Understand your report coverage</h2><p class="muted small">Import completeness and report context for the selected dates.</p></div></div>
    <div class="insight-two-column">
        <section class="card insight-coverage"><p class="portfolio-eyebrow">Comparison quality</p><h2>Imported date coverage</h2><p class="muted small">Both periods need complete, unrestricted reports for changes to be available.</p><div><span>Selected period</span><strong><?= $covered ?> / <?= $range->days() ?> days</strong></div><progress value="<?= $covered ?>" max="<?= $range->days() ?>" aria-label="Selected period imported days"></progress><div><span>Previous period</span><strong><?= $previousCovered ?> / <?= $previous->days() ?> days</strong></div><progress value="<?= $previousCovered ?>" max="<?= $previous->days() ?>" aria-label="Previous period imported days"></progress></section>
        <section class="card analytics-context"><p class="portfolio-eyebrow">Report context</p><h2>Source &amp; attribution</h2>
            <?= $view->partial('analytics/metadata', ['metadata' => $metadata]) ?>
            <p class="muted small">GA4 sessions, conversions (key events) and purchase revenue attributed to Organic Search, including Google and Bing. This is GA4 attribution, not a direct match to Search Console clicks. URLs combine GA4 hostname and landing path on HTTPS.</p>
            <p class="muted small">Selected: <?= $view->e($range->fromString()) ?>–<?= $view->e($range->toString()) ?> (<?= $covered ?>/<?= $range->days() ?> dates imported). Previous: <?= $view->e($previous->fromString()) ?>–<?= $view->e($previous->toString()) ?> (<?= $previousCovered ?>/<?= $previous->days() ?> dates imported).</p>



        </section>
    </div>
</section>
<?php if ($canManage): ?><details class="card insight-settings" id="analytics-connection"><summary>Property and history</summary><p><a href="/integrations/ga4">Configure GA4 credentials</a>. Changing the property clears its previously imported history. Daily imports refresh the latest 28 days and retain older dates. Comparisons include only dates with the latest currency and timezone; earlier data remains stored.</p>
<form class="form" method="post" action="<?= $view->e($base) ?>"><?= $view->csrfField() ?><label for="ga4-property">Numeric GA4 property ID</label><input type="text" id="ga4-property" name="property_id" value="<?= $view->e($propertyId ?? '') ?>" inputmode="numeric" maxlength="20" required><button class="button" type="submit">Link and import</button></form>
<?php if ($propertyId !== null): ?><form class="form" method="post" action="<?= $view->e($base . '/import') ?>"><?= $view->csrfField() ?><label for="ga4-history-days">Completed days to import</label><select id="ga4-history-days" name="days"><option value="28">28 days · routine refresh</option><option value="90">90 days · recent history</option><option value="180">180 days · quarterly comparisons</option><option value="365">365 days · longer history</option></select><p class="muted small">Runs in the queue and uses Google's API quota. The 10,000 daily-row limit still applies; high-traffic properties may receive partial data.</p><button class="button button-small" type="submit">Import history</button></form><?php endif; ?></details><?php endif; ?>

<?= $view->partial('partials/integration-guide', ['guide' => 'ga4']) ?>
</div>
