<?php
/** @var SeoControlCenter\Core\View\Template $view */
/** @var SeoControlCenter\Domain\Projects\Project $project */
/** @var SeoControlCenter\Domain\Opportunities\StoredOpportunity $opportunity */
/** @var SeoControlCenter\Domain\Opportunities\Playbook $action_plan */
/** @var SeoControlCenter\Modules\Opportunities\Tasks\SeoTask|null $task */
/** @var array<string, string> $data */
/** @var array<string, string> $assignees */
/** @var array{brief: bool, annotation: bool} $links */
/** @var array{brief: array<string, string>, annotation: array<string, string>} $choices */
/** @var bool $canEdit */
/** @var bool $conflict */
/** @var string $error */
use SeoControlCenter\Modules\Opportunities\Tasks\TaskInput;
$base = '/projects/' . $project->publicId;
$self = $base . '/opportunities/' . $opportunity->publicId . '/task';
$view->extend('layouts/app', ['title' => 'SEO task · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], [$base, $project->name], [$base . '/opportunities/tasks', 'Team tasks'], [null, 'Task']]]);
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'opportunities']) ?>
<section class="card">
    <h2 class="brief-url"><?= $view->e($opportunity->title) ?></h2><p><?= $view->e($opportunity->explanation) ?></p><p class="muted">Finding: <?= $view->e($opportunity->status) ?> · Priority <?= $view->e(number_format($opportunity->priority, 0)) ?>. Task progress is independent of the finding status.</p>
    <div class="actions"><a class="button button-ghost" href="<?= $view->e($base . '/opportunities/tasks') ?>">All team tasks</a><?php if ($canEdit): ?><a class="button button-ghost" href="<?= $view->e($base . '/content-briefs/new?opportunity_id=' . $opportunity->publicId) ?>">Create content brief</a><a class="button button-ghost" href="<?= $view->e($base . '/annotations?' . http_build_query(['url' => $data['url'], 'title' => mb_substr($opportunity->title, 0, 120)])) ?>">Record an SEO change</a><?php endif; ?></div>
</section>
<?= $view->partial('partials/action-plan', ['plan' => $action_plan]) ?>
<section class="card">
    <h2><?= $task === null ? 'Plan this task' : 'Task details' ?></h2>
    <?php if ($task !== null && $task->via !== null): ?><p class="muted small">Last changed via <?= $view->e($task->via) ?>, <?= $view->e($task->updatedAt) ?> UTC.</p><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice notice-error" role="alert"><?= $view->e($error) ?><?php if ($conflict): ?> <a href="<?= $view->e($self) ?>" target="_blank" rel="noopener">Open latest task</a><?php endif; ?></div><?php endif; ?>
    <form class="task-form" method="post" action="<?= $view->e($self) ?>">
        <?= $view->csrfField() ?><input type="hidden" name="revision" value="<?= $view->e($task?->revision ?? 0) ?>">
        <fieldset<?= !$canEdit ? ' disabled' : '' ?>><legend>Assignment and progress</legend><div class="task-filter-grid">
        <div class="field"><label for="task-status">Task status</label><select id="task-status" name="status"><?php foreach (TaskInput::STATUSES as $key => $label): ?><option value="<?= $view->e($key) ?>"<?= $data['status'] === $key ? ' selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="task-assignee">Assigned to</label><select id="task-assignee" name="assignee"><option value="">Unassigned</option><?php foreach ($assignees as $id => $name): ?><option value="<?= $view->e($id) ?>"<?= $data['assignee'] === $id ? ' selected' : '' ?>><?= $view->e($name) ?></option><?php endforeach; ?><?php if ($data['assignee'] !== '' && !isset($assignees[$data['assignee']])): ?><option value="<?= $view->e($data['assignee']) ?>" selected>Unavailable member — choose another</option><?php endif; ?></select></div>
        <div class="field"><label for="task-due">Due date (<?= $view->e($project->timezone) ?>)</label><input id="task-due" type="date" name="due" value="<?= $view->e($data['due']) ?>"></div>
        <div class="field"><label for="task-url">Exact page URL (optional)</label><input id="task-url" type="url" name="url" maxlength="2048" value="<?= $view->e($data['url']) ?>"></div></div>
        <div class="field"><label for="task-notes">Work notes</label><textarea id="task-notes" name="notes" rows="6" maxlength="5000" aria-describedby="task-notes-help"><?= $view->e($data['notes']) ?></textarea><p id="task-notes-help" class="muted small">A shared plain-text work plan, up to 5,000 characters. Keep secrets out. This does not edit the website or send reminders.</p></div>
        </fieldset>
        <fieldset<?= !$canEdit ? ' disabled' : '' ?>><legend>Related work (optional)</legend><p class="muted small">Choose from the 25 newest project records and any existing linked record. Record the change after you make it in your CMS, then use its impact report. Removed records are unavailable.</p><div class="task-filter-grid">
        <?php foreach (['brief' => 'Content brief', 'annotation' => 'SEO change'] as $key => $label): ?><div class="field"><label for="task-<?= $view->e($key) ?>"><?= $view->e($label) ?></label><select id="task-<?= $view->e($key) ?>" name="<?= $view->e($key) ?>"><option value="">No linked record</option><?php foreach ($choices[$key] as $id => $title): ?><option value="<?= $view->e($id) ?>"<?= $data[$key] === $id ? ' selected' : '' ?>><?= $view->e($title) ?></option><?php endforeach; ?><?php if ($data[$key] !== '' && !isset($choices[$key][$data[$key]])): ?><option value="<?= $view->e($data[$key]) ?>" selected>Unavailable record — choose another</option><?php endif; ?></select></div><?php endforeach; ?></div>
        </fieldset>
        <?php if ($canEdit): ?><button type="submit" class="button"<?= $conflict ? ' disabled' : '' ?>>Save task</button><?php else: ?><p class="muted">You have read-only access to this project.</p><?php endif; ?>
    </form>
    <div class="actions">
        <?php if ($links['brief'] && $task !== null): ?><a class="button button-ghost" href="<?= $view->e($base . '/content-briefs/' . $task->input->brief) ?>">Open linked brief</a><?php elseif ($task?->input->brief !== '' && $task !== null): ?><span class="muted">Linked brief unavailable</span><?php endif; ?>
        <?php if ($links['annotation'] && $task !== null): ?><a class="button button-ghost" href="<?= $view->e($base . '/annotations/' . $task->input->annotation . '/edit') ?>">Open SEO change</a><a class="button button-ghost" href="<?= $view->e($base . '/annotations/' . $task->input->annotation . '/impact') ?>">View change impact</a><?php elseif ($task?->input->annotation !== '' && $task !== null): ?><span class="muted">Linked SEO change unavailable</span><?php endif; ?>
    </div>
</section>
