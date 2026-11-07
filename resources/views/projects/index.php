<?php
$view->extend('layouts/app', ['title' => 'Projects']);
?>
<div class="dashboard-workspace" data-portfolio>
    <header class="page-header dashboard-hero"><div><p class="portfolio-eyebrow">Your workspace</p><h1>Projects</h1><p class="muted">Every website, one place. Open a project to explore its performance.</p></div><?php if ($canCreate): ?><a class="button" href="/projects/new"><?= $view->icon('plus') ?>New project</a><?php endif; ?></header>
    <?php if ($projects === []): ?>
        <section class="card portfolio-welcome"><span class="portfolio-empty-icon" aria-hidden="true"><?= $view->icon('folder') ?></span><h2>Your next project starts here</h2><p class="muted">No projects you can access in this workspace.</p><?php if ($canCreate): ?><a class="button" href="/projects/new">Create a project<?= $view->icon('arrow-right') ?></a><?php endif; ?></section>
    <?php else: ?>
        <?= $view->partial('partials/portfolio-toolbar', ['withMetrics' => false]) ?>
        <div class="project-cards portfolio-cards" data-portfolio-grid><?php foreach ($projects as $project): ?><?= $view->partial('partials/portfolio-card', ['project' => $project, 'summary' => null]) ?><?php endforeach; ?></div>
    <?php endif; ?>
</div>
