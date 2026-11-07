<?php
/** Shared portfolio card. A null summary means metrics have not been requested. */
$base = '/projects/' . $project->publicId;
$summary ??= null;
$search = $summary['search'] ?? null;
$bing = $summary['bing'] ?? null;
$num = static fn(float|int $value): string => number_format((float) $value);
$sources = array_values(array_filter([$search, $bing]));
$clicks = array_sum(array_map(static fn(array $source): int => $source['totals']->clicks, $sources));
$impressions = array_sum(array_map(static fn(array $source): int => $source['totals']->impressions, $sources));
$previousClicks = array_sum(array_map(static fn(array $source): int => $source['previous']->clicks, $sources));
$comparable = $sources !== [] && count(array_filter($sources, static fn(array $source): bool => $source['hasPrevious'])) === count($sources);
$change = $comparable && $previousClicks > 0 ? ($clicks - $previousClicks) / $previousClicks * 100 : null;
$range = $search['range'] ?? $bing['range'] ?? null;
$values = $search['clicks'] ?? [];
$points = [];
if (count($values) > 1) {
    $maximum = max(1.0, ...$values);
    foreach ($values as $i => $value) $points[] = sprintf('%.2f,%.2f', $i * 400 / (count($values) - 1), 84 - $value / $maximum * 72);
}
$opportunities = $summary['openOpportunities'] ?? 0;
?>
<article class="card portfolio-card" data-project-card data-name="<?= $view->e($project->name) ?>" data-domain="<?= $view->e($project->primaryDomain) ?>" data-clicks="<?= $clicks ?>" data-opportunities="<?= $opportunities ?>" data-created="<?= $view->e($project->createdAt) ?>">
    <header class="portfolio-card-header">
        <span class="portfolio-avatar" aria-hidden="true"><?= $view->e(mb_strtoupper(mb_substr($project->name, 0, 1)) ?: '?') ?></span>
        <div class="portfolio-card-identity"><h2><a href="<?= $view->e($base) ?>"><?= $view->e($project->name) ?></a></h2><p><?= $view->e($project->primaryDomain) ?></p></div>
        <a class="icon-button portfolio-open" href="<?= $view->e($base) ?>" aria-label="<?= $view->e('Open ' . $project->name) ?>"><?= $view->icon('arrow-right') ?></a>
    </header>
    <?php if ($sources !== []): ?>
        <div class="portfolio-traffic">
            <div class="portfolio-traffic-heading"><span class="portfolio-eyebrow">Organic clicks · 28 days</span><span class="portfolio-source"><?= $search !== null ? ($bing !== null ? 'Google + Bing' : 'Google') : 'Bing' ?></span></div>
            <div class="portfolio-traffic-value"><strong><?= $view->e($num($clicks)) ?></strong><?php if ($change !== null): ?><span class="portfolio-change <?= $change >= 0 ? 'delta-good' : 'delta-bad' ?>"><?= $change > 0 ? '↑' : ($change < 0 ? '↓' : '—') ?> <?= $view->e(sprintf('%+.1f%%', $change)) ?><span class="visually-hidden"> versus previous 28 days</span></span><?php else: ?><span class="muted small">No comparison yet</span><?php endif; ?></div>
            <?php if ($points !== []): ?>
                <svg class="portfolio-trend" viewBox="0 0 400 96" preserveAspectRatio="none" role="img" aria-label="<?= $view->e('Daily clicks from ' . $range->fromString() . ' to ' . $range->toString()) ?>"><path class="portfolio-trend-area" d="M0,96 L<?= $view->e(implode(' L', $points)) ?> L400,96 Z"/><polyline points="<?= $view->e(implode(' ', $points)) ?>"/></svg>
            <?php else: ?><p class="portfolio-trend-placeholder muted small">Daily trend appears when enough history is available.</p><?php endif; ?>
            <p class="portfolio-date muted small"><?= $view->e($range->fromString()) ?> – <?= $view->e($range->toString()) ?></p>
        </div>
        <dl class="portfolio-metrics">
            <div><dt>Impressions</dt><dd><?= $view->e($num($impressions)) ?></dd></div>
            <div><dt>Click-through rate</dt><dd><?= $impressions > 0 ? $view->e(number_format($clicks / $impressions * 100, 2)) . '%' : '—' ?></dd></div>
            <div><dt>Google position</dt><dd><?= $search !== null && $search['totals']->impressions > 0 ? $view->e(number_format($search['totals']->position, 1)) : '—' ?></dd></div>
        </dl>
    <?php else: ?>
        <div class="portfolio-empty">
            <span class="portfolio-empty-icon" aria-hidden="true"><?= $view->icon($summary === null ? 'folder' : 'pulse') ?></span>
            <h4><?= $summary === null ? 'Your project workspace' : 'Start with your search data' ?></h4>
            <p class="muted"><?= $summary === null ? 'Explore performance, rankings and opportunities in one place.' : 'Link Search Console to turn search activity into useful insights.' ?></p>
            <a class="button button-ghost button-small" href="<?= $view->e($base) ?><?= $summary === null ? '' : '/search-console' ?>"><?= $summary === null ? 'View project' : 'Link Search Console' ?><?= $view->icon('arrow-right') ?></a>
        </div>
        <dl class="portfolio-metadata"><div><dt>Time zone</dt><dd><?= $view->e($project->timezone) ?></dd></div><div><dt>Created</dt><dd><time datetime="<?= $view->e(str_replace(' ', 'T', $project->createdAt) . 'Z') ?>"><?= $view->e(substr($project->createdAt, 0, 10)) ?></time></dd></div></dl>
    <?php endif; ?>
    <footer class="portfolio-card-footer">
        <a class="portfolio-action" href="<?= $view->e($base) ?>/opportunities"><?= $view->icon('bolt') ?><span><?= $summary === null ? 'Explore opportunities' : $view->e($num($opportunities)) . ' open opportunit' . ($opportunities === 1 ? 'y' : 'ies') ?></span><?= $view->icon('arrow-right') ?></a>
        <nav class="portfolio-shortcuts" aria-label="<?= $view->e($project->name) ?> shortcuts"><a href="<?= $view->e($base) ?>/performance">Performance</a><a href="<?= $view->e($base) ?>/keywords">Keywords</a><a href="<?= $view->e($base) ?>/rankings">Rankings</a></nav>
    </footer>
</article>
