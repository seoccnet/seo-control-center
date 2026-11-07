<?php

declare(strict_types=1);

/**
 * Data quality inspector (DataQualityService::inspect).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var array<string, mixed> $report
 */
$view->extend('layouts/app', ['title' => 'Data quality · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'data-quality']);
$badge = static fn(string $status): string => match ($status) {
    'available', 'ok', 'recorded' => 'badge badge-ok',
    'partial', 'lagging', 'sampled', 'info' => 'badge badge-info',
    'stale', 'gaps', 'warning', 'none_recorded', 'unlinked_with_data' => 'badge badge-warning',
    default => 'badge badge-error',
};
$label = static fn(string $state): string => match ($state) {
    'ok' => 'Complete', 'gaps' => 'Missing days', 'lagging' => 'Behind', 'sampled' => 'Limited by Google',
    'not_linked' => 'Not linked', 'not_connected' => 'Not connected', 'never_imported' => 'Never imported',
    'unlinked_with_data' => 'No longer linked', 'available' => 'Available', 'stale' => 'Stale',
    'partial' => 'Sampled', 'missing' => 'Missing', default => ucfirst(str_replace('_', ' ', $state)),
};
$groupLabels = ['search' => 'Search', 'analytics' => 'Analytics', 'rankings' => 'Rankings', 'serp' => 'Results pages', 'research' => 'Research', 'backlinks' => 'Backlinks', 'technical' => 'Technical', 'ai' => 'AI visibility', 'local' => 'Local', 'context' => 'Context', 'other' => 'Other'];
$imports = $report['imports'];
$groups = [];
foreach ($report['datasets'] as $dataset) {
    $groups[$dataset['group']][] = $dataset;
}
$importCard = static function (string $title, array $data, string $setupUrl) use ($view, $badge, $label): string {
    ob_start(); ?>
    <section class="card insight-coverage">
        <div class="section-header"><div><p class="portfolio-eyebrow">Daily imports</p><h3><?= $view->e($title) ?></h3></div><span class="<?= $view->e($badge($data['state'])) ?>"><?= $view->e($label($data['state'])) ?></span></div>
        <?php if (!isset($data['from']) && !isset($data['first'])): ?>
            <p class="muted small">No stored data. <a href="<?= $view->e($setupUrl) ?>">Set it up</a> to import. Until then this source is missing, not zero.</p>
        <?php else: ?>
            <?php if (isset($data['days'])): ?>
                <div><span><?= $view->e($data['from']) ?> – <?= $view->e($data['to']) ?></span><strong><?= (int) $data['covered'] ?> / <?= (int) $data['days'] ?> days</strong></div>
                <progress value="<?= (int) $data['covered'] ?>" max="<?= max(1, (int) $data['days']) ?>" aria-label="<?= $view->e($title) ?> imported days"></progress>
            <?php else: ?>
                <p class="small">Stored <?= $view->e((string) $data['first']) ?> – <?= $view->e((string) $data['last']) ?></p>
            <?php endif; ?>
            <p class="muted small">Latest day <?= $view->e((string) ($data['last'] ?? $data['to'] ?? '')) ?> · <?= $view->e(match ((int) ($data['lag_days'] ?? 0)) { 0 => 'today', 1 => '1 day ago', default => (int) $data['lag_days'] . ' days ago' }) ?> (expected within <?= (int) ($data['expected_lag_days'] ?? 0) ?> days)<?php if (isset($data['zero_days']) && $data['zero_days'] > 0): ?> · <?= (int) $data['zero_days'] ?> measured zero <?= $data['zero_days'] === 1 ? 'day' : 'days' ?><?php endif; ?></p>
            <?php if (($data['missing_ranges'] ?? []) !== []): ?>
                <p class="small"><strong>Missing days</strong> (never imported completely, not zero):</p>
                <ul class="small"><?php foreach ($data['missing_ranges'] as $range): ?><li><?= $view->e($range['from'] === $range['to'] ? $range['from'] : $range['from'] . ' – ' . $range['to']) ?> (<?= (int) $range['days'] ?> <?= $range['days'] === 1 ? 'day' : 'days' ?>)</li><?php endforeach; ?></ul>
                <?php if ($data['ranges_truncated'] ?? false): ?><p class="muted small">Only the 20 most recent gaps are listed.</p><?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
        <?php foreach ($data['notes'] ?? [] as $note): ?><p class="muted small"><?= $view->e($note) ?></p><?php endforeach; ?>
    </section>
    <?php return (string) ob_get_clean();
};
$base = '/projects/' . $project->publicId;
$ga4 = $imports['ga4'];
?>
<div class="insight-workspace data-quality-workspace">
<header class="insight-intro"><div><p class="portfolio-eyebrow">Manage · Data quality</p><h2>Can you trust what you see?</h2><p class="muted">Which data is current, which is old or sampled, which days were never imported, and which sources should not be compared directly. Missing data is never counted as zero.</p></div><nav class="insight-segments" aria-label="Data quality sections"><a href="#dq-checks">Checks</a><a href="#dq-imports">Imports</a><a href="#dq-ga4">GA4</a><a href="#dq-datasets">All datasets</a></nav></header>

<div class="insight-metric-grid" aria-label="Dataset status">
<?php foreach ([['available', 'Available', 'fresh and complete'], ['partial', 'Sampled', 'top rows only'], ['stale', 'Stale', 'older than expected'], ['missing', 'Missing', 'no usable data']] as [$key, $title, $note]): ?>
    <section class="card insight-metric"><p class="portfolio-eyebrow"><?= $view->e($title) ?></p><strong><?= (int) $report['summary'][$key] ?></strong><p class="muted small"><?= $view->e($note) ?></p></section>
<?php endforeach; ?>
</div>

<section class="card" id="dq-checks"><div class="section-header"><div><p class="portfolio-eyebrow">Before you compare</p><h2>Checks</h2></div><span class="badge"><?= count($report['compatibility']) ?></span></div>
<?php if ($report['compatibility'] === []): ?><p class="muted">No gaps, sampling or incompatible combinations found in the stored data.</p><?php endif; ?>
<ul class="data-quality-checks">
<?php foreach ($report['compatibility'] as $item): ?>
    <li><span class="<?= $view->e($badge($item['level'])) ?>"><?= $view->e($item['level'] === 'warning' ? 'Check' : 'Note') ?></span> <strong><?= $view->e($item['title']) ?></strong><br><span class="muted small"><?= $view->e($item['detail']) ?></span></li>
<?php endforeach; ?>
</ul>
</section>

<section id="dq-imports" aria-labelledby="dq-imports-title"><div class="section-header"><div><p class="portfolio-eyebrow">Last <?= SeoControlCenter\Modules\DataQuality\DataQualityService::WINDOW_DAYS ?> imported days</p><h2 id="dq-imports-title">Import completeness</h2></div></div>
<div class="insight-two-column">
    <?= $importCard('Google Search Console', $imports['search_console'], $base . '/edit') ?>
    <?= $importCard('Bing Webmaster Tools', $imports['bing'], '/integrations/bing') ?>
</div>
</section>

<section class="card" id="dq-ga4"><div class="section-header"><div><p class="portfolio-eyebrow">Analytics coverage</p><h2>Google Analytics 4</h2></div><span class="<?= $view->e($badge($ga4['state'])) ?>"><?= $view->e($label($ga4['state'])) ?></span></div>
<?php if (isset($ga4['days'])): ?>
    <div class="insight-two-column">
        <?= $importCard('Organic landing pages', $ga4, $base . '/analytics') ?>
        <section class="card insight-coverage"><p class="portfolio-eyebrow">What GA4 recorded</p><h3><?= $view->e($ga4['from']) ?> – <?= $view->e($ga4['to']) ?></h3>
            <dl>
                <dt>Organic sessions</dt><dd><?= $view->e(number_format((int) $ga4['sessions'])) ?></dd>
                <dt>Key events</dt><dd><?= $view->e(number_format((float) $ga4['key_events'], 2)) ?> <span class="<?= $view->e($badge($ga4['key_events_state'])) ?>"><?= $view->e($ga4['key_events_state'] === 'none_recorded' ? 'None recorded' : ($ga4['key_events_state'] === 'recorded' ? 'Recorded' : 'No sessions')) ?></span></dd>
                <dt>Purchase revenue</dt><dd><?= $view->e(number_format((float) $ga4['revenue'], 2) . ' ' . $ga4['currency']) ?> <span class="<?= $view->e($badge($ga4['revenue_state'])) ?>"><?= $view->e($ga4['revenue_state'] === 'none_recorded' ? 'None recorded' : ($ga4['revenue_state'] === 'recorded' ? 'Recorded' : 'No sessions')) ?></span></dd>
                <dt>Report limits</dt><dd><?= $ga4['limited'] ? 'Row limit reached' : 'Within row limit' ?> · <?= $ga4['thresholded'] ? 'Privacy thresholds applied' : 'No thresholds' ?></dd>
                <dt>Property timezone</dt><dd><?= $view->e($ga4['timezone']) ?></dd>
            </dl>
            <p class="muted small">"None recorded" means GA4 had organic sessions but reported no key events or revenue. SEOcc cannot tell whether nothing is configured or nothing happened.</p>
        </section>
    </div>
<?php else: ?>
    <p class="muted small">No GA4 data for this project. <a href="<?= $view->e($base . '/analytics') ?>">Connect GA4</a> to see sessions, key events and revenue; until then value estimates use clicks.</p>
<?php endif; ?>
<?php $business = $report['business_measurement'] ?? null; if (is_array($business)): ?>
<p class="dq-business"><strong>Business events:</strong> <span class="<?= $view->e(match ($business['state']) { 'observed' => 'badge badge-ok', 'failed' => 'badge badge-error', 'not_observed' => 'badge badge-warning', 'not_configured' => 'badge badge-info', default => 'badge' }) ?>"><?= $view->e($business['label']) ?></span> <?= $view->e($business['summary']) ?> <a href="<?= $view->e($base . '/analytics#business-measurement') ?>">Review business measurement</a></p>
<?php endif; ?>
<details><summary>Attribution limits</summary><ul class="small"><?php foreach ($ga4['limitations'] as $limit): ?><li><?= $view->e($limit) ?></li><?php endforeach; ?></ul></details>
</section>

<section class="card" id="dq-datasets"><div class="section-header"><div><p class="portfolio-eyebrow">Opportunity engine coverage</p><h2>All datasets</h2></div><span class="badge"><?= count($report['datasets']) ?></span></div>
<p class="muted small">Freshness uses the same rules as Opportunities. Times are dates of the newest stored data, checked <?= $view->e($report['checked_at_utc']) ?> UTC.</p>
<div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Dataset</th><th scope="col">Status</th><th scope="col">Data ends</th><th scope="col">Details</th></tr></thead><tbody>
<?php foreach ($groups as $group => $rows): ?>
    <tr><th scope="rowgroup" colspan="4"><?= $view->e($groupLabels[$group] ?? $group) ?></th></tr>
    <?php foreach ($rows as $row): ?>
        <tr><td><?= $view->e($row['label']) ?></td><td><span class="<?= $view->e($badge($row['status'])) ?>"><?= $view->e($label($row['status'])) ?></span></td><td><?= $view->e($row['last_date'] ?? '—') ?></td><td class="small"><?= $view->e(trim(($row['detail'] ?? '') . ' ' . ($row['note'] ?? ''))) ?></td></tr>
    <?php endforeach; ?>
<?php endforeach; ?>
</tbody></table></div>
<details><summary>How to read a status</summary><dl class="small"><?php foreach ($report['interpretation'] as $key => $text): ?><dt><?= $view->e($label($key)) ?></dt><dd><?= $view->e($text) ?></dd><?php endforeach; ?></dl></details>
</section>
</div>
