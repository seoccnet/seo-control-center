<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var list<array{page: string, estimated_monthly_clicks: ?float, unknown_estimates: int, actions: list<SeoControlCenter\Domain\Opportunities\StoredOpportunity>, notice: string}> $plans
 * @var bool $truncated
 * @var array<string, SeoControlCenter\Domain\Opportunities\Playbook> $action_plans
 */
$view->extend('layouts/app', ['title' => 'Page action plans · ' . $project->name]);
$base = '/projects/' . $project->publicId . '/opportunities';
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'opportunities']) ?>
<h2>Page action plans</h2>
<p><a href="<?= $view->e($base) ?>">All findings</a></p>
<p class="muted">One plan per recorded landing path. Actions are ordered by priority. Estimates from overlapping rules are not added; unknown gains remain unknown. Shows up to 200 plans.</p>
<?php if ($truncated): ?><p class="notice">The stored catalog is incomplete at the 5,000-finding bound; open plans are withheld to preserve workflow decisions.</p><?php endif; ?>
<?php foreach ($plans as $plan): ?>
<section class="card">
<h3><?= $view->e($plan['page']) ?></h3>
<p>Estimated monthly clicks: <?= $plan['estimated_monthly_clicks'] !== null ? $view->e(number_format($plan['estimated_monthly_clicks'], 1)) : 'unknown' ?> · <?= $view->e($plan['unknown_estimates']) ?> actions have unknown gains.</p>
<p class="muted small"><?= $view->e($plan['notice']) ?></p>
<ol><?php foreach ($plan['actions'] as $action): ?><li><strong><?= $view->e($action->title) ?></strong> · priority <?= $view->e($action->priority) ?><p><?= $view->e($action->explanation) ?></p><?php if (isset($action_plans[$action->publicId])): ?><?= $view->partial('partials/action-plan', ['plan' => $action_plans[$action->publicId], 'compact' => true]) ?><?php endif; ?><details><summary>Supporting evidence</summary><dl><?php foreach ($action->evidence as $item): ?><dt><?= $view->e($item['label']) ?></dt><dd><?= $view->e($item['value']) ?></dd><?php endforeach; ?></dl></details><a href="<?= $view->e($base . '/' . $action->publicId . '/task') ?>">Task</a></li><?php endforeach; ?></ol>
</section>
<?php endforeach; ?>
<?php if ($plans === []): ?><p>No open page plans with a recorded landing URL.</p><?php endif; ?>
