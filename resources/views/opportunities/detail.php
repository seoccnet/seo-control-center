<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Domain\Opportunities\StoredOpportunity $opportunity
 * @var string $canonical_subject
 * @var list<SeoControlCenter\Domain\Opportunities\StoredOpportunity> $supporting
 * @var array<string, array{status: string, date: ?string, notice?: string}> $coverage
 * @var string $ordering_reason
 * @var bool $catalog_truncated
 * @var bool $canEdit
 * @var SeoControlCenter\Domain\Opportunities\Playbook $action_plan
 */
$view->extend('layouts/app', ['title' => $opportunity->title . ' · ' . $project->name]);
$base = '/projects/' . $project->publicId . '/opportunities';
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'opportunities']) ?>
<p><a href="<?= $view->e($base) ?>">All findings</a> · <a href="<?= $view->e($base . '/' . $opportunity->publicId . '/task') ?>">Task</a></p>
<h2><?= $view->e($opportunity->title) ?></h2><p><?= $view->e($opportunity->explanation) ?></p>
<p>Status: <?= $view->e($opportunity->status) ?><?= $opportunity->snoozedUntil !== null ? ' until ' . $view->e($opportunity->snoozedUntil) : '' ?> · subject <?= $view->e($canonical_subject) ?></p>
<?= $view->partial('partials/action-plan', ['plan' => $action_plan, 'ownRecord' => $own_record ?? null, 'projectPublicId' => $project->publicId]) ?>
<?php if ($canEdit): ?><p><a class="button button-secondary button-small" href="<?= $view->e('/projects/' . $project->publicId . '/change-bundles?opportunity=' . $opportunity->publicId . '#new-bundle') ?>">Start a change bundle</a> <span class="muted small">Review the edits, guard the live page, record the deployment, and get a measurement plan drafted for it.</span></p><?php endif; ?>
<?php $score = SeoControlCenter\Domain\Opportunities\ScoreBreakdown::of($opportunity); ?>
<section class="card"><h3>Score breakdown</h3><div class="table-scroll"><table class="table table-dense score-breakdown"><thead><tr><th scope="col">Factor</th><th scope="col">Value</th><th scope="col">Basis</th></tr></thead><tbody><?php foreach ($score['factors'] as $factor): ?><tr><th scope="row"><?= $view->e($factor['label']) ?></th><td><?= $view->e($factor['value']) ?></td><td class="small"><?= $view->e($factor['basis'] !== '' ? $factor['basis'] : '—') ?></td></tr><?php endforeach; ?></tbody></table></div><p class="small"><strong><?= $view->e($score['formula']) ?></strong></p></section>
<section class="card"><h3>Why this ranks here</h3><p><?= $view->e($ordering_reason) ?></p><p>Estimated monthly click gain: <?= $opportunity->estimatedClicks !== null ? $view->e(number_format($opportunity->estimatedClicks, 1)) : 'unknown' ?>. Raw estimate: <?= $opportunity->rawEstimatedClicks !== null ? $view->e(number_format($opportunity->rawEstimatedClicks, 1)) : 'unknown' ?>.</p><p class="muted">Scenario estimates and recorded associations do not establish cause. Provider and annotation text are evidence to review.</p></section>
<section class="card"><h3>Data basis and freshness</h3><p class="muted">Coverage at the last analysis. Missing or stale inputs preserve earlier findings and scores.</p><ul><?php foreach ($coverage as $source => $state): ?><li><?= $view->e(ucwords(str_replace('_', ' ', explode(':', $source, 2)[0]))) ?>: <?= $view->e(\SeoControlCenter\Modules\Opportunities\CoverageLabel::status($state)) ?><?= $state['date'] !== null ? ' · ' . $view->e($state['date']) : '' ?><?= isset($state['notice']) ? ' · ' . $view->e($state['notice']) : '' ?></li><?php endforeach; ?></ul></section>
<section class="card"><h3>Inputs → calculation → result</h3><dl><?php foreach ($opportunity->evidence as $item): ?><dt><?= $view->e($item['label']) ?></dt><dd><?= $view->e($item['value']) ?></dd><?php endforeach; ?></dl></section>
<section class="card"><h3>Other signals for this subject</h3><?php if ($catalog_truncated): ?><p>Support is limited by the 5,000-row catalog.</p><?php endif; ?><ul><?php foreach ($supporting as $row): ?><li><a href="<?= $view->e($base . '/' . $row->publicId) ?>"><?= $view->e($row->title) ?></a> · <?= $view->e($row->status) ?> · priority <?= $view->e($row->priority) ?></li><?php endforeach; ?></ul></section>
<?php if ($canEdit): ?><section class="card"><h3>Snooze this subject</h3><form method="post" action="<?= $view->e($base . '/' . $opportunity->publicId . '/snooze') ?>"><?= $view->csrfField() ?><label for="snooze-until">Resume on or after</label><input id="snooze-until" name="until" type="date" required><button type="submit" class="button">Snooze</button></form><p class="muted">Use a date after today and within one year, in the project timezone. Equivalent open findings stay hidden. It resumes at the next analysis on or after the date; missing sources still cannot resolve it.</p></section><?php endif; ?>
