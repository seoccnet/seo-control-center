<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var list<SeoControlCenter\Domain\Opportunities\StoredOpportunity> $opportunities
 * @var bool $truncated
 * @var string $notice
 */
$view->extend('layouts/app', ['title' => 'Top 10 this week · ' . $project->name]);
$base = '/projects/' . $project->publicId . '/opportunities';
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'opportunities']) ?>
<h2>Top 10 this week</h2><p><a href="<?= $view->e($base) ?>">All findings</a> · <a href="<?= $view->e($base . '/by-page') ?>">Page plans</a></p>
<p class="muted"><?= $view->e($notice) ?></p>
<?php if ($truncated): ?><p class="notice">The catalog exceeded its bound; selection is withheld to preserve closed decisions.</p><?php endif; ?>
<ol><?php foreach ($opportunities as $index => $row): ?><li class="card"><h3><?= $view->e($row->title) ?></h3><p><?= $view->e(SeoControlCenter\Domain\Opportunities\OpportunityPrioritizer::explanation($row, $opportunities[$index + 1] ?? null)) ?></p><p><?= $view->e($row->explanation) ?></p><details><summary>Calculation and evidence</summary><dl><?php foreach ($row->evidence as $item): ?><dt><?= $view->e($item['label']) ?></dt><dd><?= $view->e($item['value']) ?></dd><?php endforeach; ?></dl></details><a href="<?= $view->e($base . '/' . $row->publicId . '/task') ?>">Task</a></li><?php endforeach; ?></ol>
<?php if ($opportunities === []): ?><p>No open findings to select.</p><?php endif; ?>
