<?php
/** Project dashboard. Projections come from authorized persisted readers. */
$view->extend('layouts/app', ['title' => $project->name, 'crumbs' => [['/projects', 'Projects'], [null, $project->name]]]);
$base = '/projects/' . $project->publicId;
$range = $evidence['range'];
$analytics = $evidence['analytics'];
$hasEvidence = $search !== null || $bing !== null || $analytics['totals'] !== null || $evidence['ranking']['date'] !== null || $evidence['demand'] !== [] || $evidence['audit'] !== null || $evidence['vitals'] !== [] || $evidence['mentions'] !== [] || $evidence['ai']['date'] !== null || $evidence['backlinks'] !== [] || $evidence['changes'] !== [] || $opportunities !== [] || $evidence['local'] !== null || $evidence['indexing']['total'] > 0 || $evidence['rendered']?->fetchedAt !== null || $evidence['brand']['history'] !== [];
if ($link === null) {
    [$meta, $metaStatus] = ['Search Console not linked', 'warn'];
} elseif ($sync !== null && $sync->status === 'error') {
    [$meta, $metaStatus] = ['Search Console import failed', 'error'];
} else {
    [$meta, $metaStatus] = ['Search Console linked' . ($sync?->lastSuccessAt !== null ? ' · imported ' . $sync->lastSuccessAt . ' UTC' : ' · first import queued'), 'ok'];
}
// Cards with no saved evidence move to one "Not set up yet" strip instead of showing empty states.
$missing = [
    'rankings' => $evidence['ranking']['date'] === null,
    'landings' => $analytics['totals'] === null,
    'risks' => $evidence['audit'] === null && $evidence['indexing']['total'] === 0 && $evidence['vitals'] === [] && $evidence['backlinks'] === [] && $evidence['rendered']?->fetchedAt === null && ($evidence['new'] ?? null) === null && ($evidence['lost'] ?? null) === null,
    'demand' => $evidence['near'] === [] && $evidence['demand'] === [] && $evidence['cannibalization'] === [],
    'visibility' => ($rankings['latest'] ?? null) === null && $evidence['ai']['current'] === null && $evidence['mentions'] === [] && $evidence['brand']['history'] === [],
];
$actions = $link !== null && $canImport ? '<form method="post" action="' . $view->e($base) . '/performance/import" class="inline-form">' . $view->csrfField() . '<button type="submit" class="button">' . $view->icon('refresh') . 'Import now</button></form>' : null;
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'overview', 'meta' => $meta, 'metaStatus' => $metaStatus, 'actions' => $actions, 'notice' => $canImport ? $view->partial('partials/wordpress-plugin-notice', ['project' => $project]) : null]) ?>
<?= $widgets(SeoControlCenter\Core\View\LayoutWidgets::PROJECT_OVERVIEW, $view) ?>
<div class="overview-workspace decision-overview">
<div class="overview-heading"><div><h2 id="overview-heading" tabindex="-1">Performance & priorities</h2><p class="muted small"><?= $view->e($range->fromString()) ?> – <?= $view->e($range->toString()) ?> · previous period <?= $view->e($range->previous()->fromString()) ?> – <?= $view->e($range->previous()->toString()) ?> · Other sources show their own observation dates.</p></div><div class="overview-heading-actions"><?= $view->partial('partials/range-picker', ['ranges' => $ranges, 'current' => $rangeKey, 'href' => $base]) ?><a class="button button-ghost button-small" href="<?= $view->e($base) ?>/reports">Reports<?= $view->icon('arrow-right') ?></a></div></div>
<?= $view->partial('partials/overview-summary', compact('search', 'bing', 'evidence', 'base')) ?>
<?php if (!$hasEvidence): ?>
<?= $view->partial('partials/prognosis-chart', ['search' => $search, 'bing' => $bing, 'base' => $base, 'project' => $project, 'annotations' => $annotations ?? [], 'assetVersion' => $assetVersion ?? '0']) ?>
<section class="card overview-start" aria-labelledby="overview-start-heading"><p class="integration-kicker">First evidence</p><h2 id="overview-start-heading">No imported results yet</h2><p class="muted">Connect a source, import performance, or run an audit. Comparisons and priorities appear when saved results support them.</p><div class="overview-start-actions"><a class="button button-small" href="<?= $view->e($base) ?>/search-console">Connect Search Console</a><a class="button button-ghost button-small" href="<?= $view->e($base) ?>/analytics">Connect GA4</a><a class="button button-ghost button-small" href="<?= $view->e($base) ?>/rankings">Set up tracking</a><a class="button button-ghost button-small" href="<?= $view->e($base) ?>/site-audit">Run site audit</a></div></section>
<?php else: ?>
<?php $hasGrowth = !$missing['risks'] || !$missing['demand'] || !$missing['visibility'] || $evidence['local'] !== null; ?>
<nav class="overview-jump" aria-label="Overview sections"><a href="#overview-outlook-title">Outlook &amp; audience</a><a href="#overview-next-title">Do next</a><a href="#overview-why-title">Why it changed</a><?php if ($hasGrowth): ?><a href="#overview-growth-title">Growth &amp; risks</a><?php endif; ?></nav>
<section class="overview-section" aria-labelledby="overview-outlook-title">
<div class="overview-section-head"><h2 class="overview-section-title" id="overview-outlook-title">Outlook &amp; audience</h2><p class="overview-section-intro">Where Google clicks are heading, and which engines, countries and devices they come from.</p></div>
<div class="overview-trend-row<?= $search !== null ? ' has-audience' : '' ?>">
<?= $view->partial('partials/prognosis-chart', ['search' => $search, 'bing' => $bing, 'base' => $base, 'project' => $project, 'annotations' => $annotations ?? [], 'assetVersion' => $assetVersion ?? '0']) ?>
<?php if ($search !== null): ?><?= $view->partial('partials/overview-audience', compact('search', 'evidence', 'base', 'rangeKey')) ?><?php endif; ?>
</div>
</section>
<section class="overview-section" aria-labelledby="overview-next-title">
<div class="overview-section-head"><h2 class="overview-section-title" id="overview-next-title">Do next</h2><p class="overview-section-intro">The few changes most likely to win clicks, ranked by importance.</p></div>
<div class="overview-decision-grid overview-trend-context">
<?= $view->partial('partials/overview-actions', compact('evidence', 'opportunities', 'openOpportunities', 'lastAnalyzedAt', 'base', 'rangeKey')) ?>
</div>
</section>
<section class="overview-section" aria-labelledby="overview-why-title">
<div class="overview-section-head"><h2 class="overview-section-title" id="overview-why-title">Why it changed</h2><p class="overview-section-intro">Queries and pages that gained or lost clicks against the previous period, next to what you changed and where tracked keywords rank.</p></div>
<div class="overview-decision-grid overview-section-grid is-why">
<div class="overview-evidence-stack">
<?= $view->partial('partials/overview-movers', compact('evidence', 'base', 'rangeKey')) ?>
<?php if (!$missing['landings']): ?><?= $view->partial('partials/overview-landings', compact('evidence', 'base')) ?><?php endif; ?>
</div>
<div class="overview-evidence-stack">
<?php if (!$missing['rankings']): ?><?= $view->partial('partials/overview-rankings', compact('evidence', 'base')) ?><?php endif; ?>
<?= $view->partial('partials/overview-changes', compact('evidence', 'base')) ?>
</div>
</div>
</section>
<?php if ($hasGrowth): ?>
<section class="overview-section" aria-labelledby="overview-growth-title">
<div class="overview-section-head"><h2 class="overview-section-title" id="overview-growth-title">Growth &amp; risks</h2><p class="overview-section-intro">Technical problems that can cost traffic, demand you do not rank for yet, and how you compare with competitors.</p></div>
<div class="overview-decision-grid overview-section-grid is-growth">
<?php if (!$missing['risks']): ?><div class="overview-evidence-stack"><?= $view->partial('partials/overview-risks', compact('evidence', 'project', 'base')) ?></div><?php endif; ?>
<?php if (!$missing['demand']): ?><div class="overview-evidence-stack"><?= $view->partial('partials/overview-demand', compact('evidence', 'base')) ?></div><?php endif; ?>
<?php if (!$missing['visibility']): ?><div class="overview-evidence-stack"><?= $view->partial('partials/overview-visibility', compact('evidence', 'rankings', 'competitors', 'competitorCount', 'project', 'base')) ?></div><?php endif; ?>
<?php if ($evidence['local'] !== null): ?><div class="overview-evidence-stack"><?= $view->partial('partials/overview-local', compact('evidence', 'base')) ?></div><?php endif; ?>
</div>
</section>
<?php endif; ?>
<?php $setup = array_filter([
    'rankings' => ['Rank tracking', 'No saved rank checks yet.', '/rankings', 'Set up rank tracking'],
    'landings' => ['Visits & outcomes', 'No GA4 Organic Search data imported for these dates.', '/analytics', $analytics['propertyId'] === null ? 'Connect a GA4 property' : 'Review GA4 imports'],
    'risks' => ['Site audit', 'No completed site audit or saved technical checks.', '/site-audit', 'Run a site audit'],
    'demand' => ['Keyword research', 'No striking-distance queries or completed research for this period.', '/keywords/research', 'Start keyword research'],
    'visibility' => ['Competitors & AI visibility', 'Needs saved rank checks or AI citation checks.', '/ai-visibility', 'Set up AI visibility'],
], static fn(string $key): bool => $missing[$key], ARRAY_FILTER_USE_KEY); ?>
<?php if ($setup !== []): ?>
<section class="overview-setup" aria-labelledby="overview-setup-title">
<div class="overview-section-head"><h2 class="overview-section-title" id="overview-setup-title">Not set up yet</h2><p class="overview-section-intro">Each source adds a card above once it has saved data.</p></div>
<ul class="overview-setup-list"><?php foreach ($setup as [$label, $reason, $href, $cta]): ?><li><strong><?= $view->e($label) ?></strong><span class="muted small"><?= $view->e($reason) ?></span><a class="small" href="<?= $view->e($base . $href) ?>"><?= $view->e($cta) ?> →</a></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
<?php endif; ?>
</div>
