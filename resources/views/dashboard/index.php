<?php
$view->extend('layouts/app', ['title' => 'Dashboard']);
$num = static fn(float|int $value): string => number_format((float) $value);
$firstName = explode(' ', trim($user->name))[0] ?? '';
$reporting = 0; $totalClicks = 0; $totalImpressions = 0; $totalOpportunities = 0;
foreach ($summaries as $summary) {
    $sources = array_filter([$summary['search'], $summary['bing']]);
    if ($sources !== []) $reporting++;
    foreach ($sources as $source) { $totalClicks += $source['totals']->clicks; $totalImpressions += $source['totals']->impressions; }
    $totalOpportunities += $summary['openOpportunities'];
}
?>
<div class="dashboard-workspace" data-portfolio>
    <header class="page-header dashboard-hero">
        <div><p class="portfolio-eyebrow">Workspace overview</p><h1><?= $view->e($firstName !== '' ? 'Welcome back, ' . $firstName : 'Dashboard') ?></h1><p class="muted"><?= $context !== null ? $view->e($context->workspace()->name) . ' · ' . $view->e($context->current->roleLabel) : 'Your SEO workspace' ?></p></div>
        <div class="actions"><a class="button button-ghost" href="/projects"><?= $view->icon('folder') ?>All projects</a><?php if ($canCreate): ?><a class="button" href="/projects/new"><?= $view->icon('plus') ?>New project</a><?php endif; ?></div>
    </header>
    <?= $widgets(SeoControlCenter\Core\View\LayoutWidgets::DASHBOARD, $view) ?>
    <?php if ($context === null): ?>
        <section class="card portfolio-welcome"><span class="portfolio-empty-icon" aria-hidden="true"><?= $view->icon('users') ?></span><h2>No workspace yet</h2><p class="muted">Your account is not a member of any workspace. Ask a workspace owner to add you.</p></section>
    <?php elseif ($projects === []): ?>
        <section class="card portfolio-welcome"><span class="portfolio-empty-icon" aria-hidden="true"><?= $view->icon('folder') ?></span><h2>Create your first project</h2><p class="muted">Bring your search performance, keywords and competitors into one clear workspace.</p><?php if ($canCreate): ?><a class="button" href="/projects/new"><?= $view->icon('plus') ?>New project</a><?php endif; ?></section>
    <?php else: ?>
        <section class="portfolio-overview" aria-label="Workspace performance summary">
            <div class="card portfolio-summary"><span class="portfolio-summary-icon" aria-hidden="true"><?= $view->icon('folder') ?></span><span class="portfolio-eyebrow">Your portfolio</span><strong><?= $num(count($projects)) ?></strong><p class="muted small"><?= $reporting ?> projects with search data in the loaded summaries</p></div>
            <div class="card portfolio-summary"><span class="portfolio-summary-icon" aria-hidden="true"><?= $view->icon('pulse') ?></span><span class="portfolio-eyebrow">Organic clicks</span><strong><?= $reporting > 0 ? $num($totalClicks) : '—' ?></strong><p class="muted small">Across each project's latest 28 days</p></div>
            <div class="card portfolio-summary"><span class="portfolio-summary-icon" aria-hidden="true"><?= $view->icon('eye') ?></span><span class="portfolio-eyebrow">Search impressions</span><strong><?= $reporting > 0 ? $num($totalImpressions) : '—' ?></strong><p class="muted small">Google and Bing, where linked</p></div>
            <a class="card portfolio-summary portfolio-summary-link" href="#portfolio-projects"><span class="portfolio-summary-icon" aria-hidden="true"><?= $view->icon('bolt') ?></span><span class="portfolio-eyebrow">Open opportunities</span><strong><?= $num($totalOpportunities) ?></strong><p class="muted small">Find your next move<?= $view->icon('arrow-right') ?></p></a>
        </section>
        <p class="portfolio-scope muted small">Summary covers <?= count($summaries) ?> of <?= count($projects) ?> accessible projects. Date windows may differ by project; all figures use stored data.</p>
        <section id="portfolio-projects" aria-labelledby="projects-heading">
            <div class="portfolio-section-heading"><div><h2 id="projects-heading">Your projects</h2><p class="muted">A clear view of every website. Choose where to focus next.</p></div><span class="portfolio-source"><?= count($projects) ?> project<?= count($projects) === 1 ? '' : 's' ?></span></div>
            <?= $view->partial('partials/portfolio-toolbar', ['withMetrics' => true]) ?>
            <div class="project-cards portfolio-cards" data-portfolio-grid><?php foreach ($projects as $i => $project): ?><?= $view->partial('partials/portfolio-card', ['project' => $project, 'summary' => $summaries[$i] ?? null]) ?><?php endforeach; ?></div>
            <?php if (count($projects) > count($summaries)): ?><p class="muted small portfolio-scope">Showing figures for the first <?= count($summaries) ?> projects. Open a project for its full performance.</p><?php endif; ?>
        </section>
    <?php endif; ?>
</div>
