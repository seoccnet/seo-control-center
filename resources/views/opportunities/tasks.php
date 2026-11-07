<?php
/** @var SeoControlCenter\Core\View\Template $view */
/** @var SeoControlCenter\Domain\Projects\Project $project */
/** @var list<SeoControlCenter\Modules\Opportunities\Tasks\SeoTask> $items */
/** @var SeoControlCenter\Modules\Opportunities\Tasks\TaskFilter $filter */
/** @var array<string, string> $assignees */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var string $today */
use SeoControlCenter\Modules\Opportunities\Tasks\TaskInput;
$base = '/projects/' . $project->publicId;
$self = $base . '/opportunities/tasks';
$query = static fn(int $number): string => '?' . http_build_query(['page' => $number, ...$filter->query()]);
$view->extend('layouts/app', ['title' => 'Team tasks · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], [$base, $project->name], [$base . '/opportunities', 'Opportunities'], [null, 'Team tasks']]]);
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'opportunities']) ?>
<nav class="filter-row" aria-label="Opportunity views"><a class="chip" href="<?= $view->e($base . '/opportunities') ?>">SEO findings</a><a class="chip" aria-current="page" href="<?= $view->e($self) ?>">Team tasks</a></nav>
<section class="card">
    <div class="sync-row"><div><h2>Team tasks</h2><p class="muted">Turn a finding into an assigned piece of work. Open a finding and choose Manage task to add it here.</p></div><strong><?= $view->e($total) ?> matching tasks</strong></div>
    <details><summary>How task status works</summary><p>To do, In progress and Done describe your team's work. SEO findings keep their separate detection status. An analysis may resolve a finding while its task still needs review. Marking a task done does not prove the issue is fixed or change your website. Due dates use <?= $view->e($project->timezone) ?>; today is <?= $view->e($today) ?>. Tasks do not send reminders or call providers.</p></details>
    <form method="get" action="<?= $view->e($self) ?>" class="task-filter-grid">
        <div class="field"><label for="task-search">Search title, notes or URL</label><input id="task-search" name="search" maxlength="200" value="<?= $view->e($filter->search) ?>" type="search"></div>
        <div class="field"><label for="task-status">Task status</label><select id="task-status" name="status"><option value="">All statuses</option><?php foreach (TaskInput::STATUSES as $key => $label): ?><option value="<?= $view->e($key) ?>"<?= $filter->status === $key ? ' selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="task-assignee">Assigned to</label><select id="task-assignee" name="assignee"><option value="">Everyone</option><option value="unassigned"<?= $filter->assignee === 'unassigned' ? ' selected' : '' ?>>Unassigned</option><?php foreach ($assignees as $id => $name): ?><option value="<?= $view->e($id) ?>"<?= $filter->assignee === $id ? ' selected' : '' ?>><?= $view->e($name) ?></option><?php endforeach; ?><?php if ($filter->assignee !== '' && $filter->assignee !== 'unassigned' && !isset($assignees[$filter->assignee])): ?><option value="<?= $view->e($filter->assignee) ?>" selected>Unavailable assignee</option><?php endif; ?></select></div>
        <div class="field"><label for="task-priority">Finding priority</label><select id="task-priority" name="priority"><option value="">Any priority</option><?php foreach ([50, 75] as $minimum): ?><option value="<?= $view->e($minimum) ?>"<?= $filter->priority === $minimum ? ' selected' : '' ?>><?= $view->e($minimum) ?> or higher</option><?php endforeach; ?></select></div>
        <div class="field"><label for="task-url">Exact page URL</label><input id="task-url" type="url" name="url" maxlength="2048" value="<?= $view->e($filter->url) ?>" placeholder="https://<?= $view->e($project->primaryDomain) ?>/page"></div>
        <div class="field"><label for="task-overdue">Due dates</label><select id="task-overdue" name="overdue"><option value="">Any due date</option><option value="1"<?= $filter->overdue ? ' selected' : '' ?>>Overdue unfinished tasks</option></select></div>
        <div class="actions"><button class="button" type="submit">Apply filters</button><a class="button button-ghost" href="<?= $view->e($self) ?>">Reset</a></div>
    </form>
</section>
<?php if ($items === []): ?><section class="card"><h2>No matching tasks</h2><p class="muted">Try clearing filters, or open an SEO finding to plan its work.</p><a class="button button-ghost" href="<?= $view->e($base . '/opportunities') ?>">View SEO findings</a></section><?php endif; ?>
<?php foreach ($items as $task): ?>
<article class="card opportunity">
    <header class="opportunity-header"><div><span class="badge badge-info"><?= $view->e(TaskInput::STATUSES[$task->input->status]) ?></span><?php if ($task->input->due !== '' && $task->input->due < $today && $task->input->status !== 'done'): ?> <span class="badge badge-warning">Overdue</span><?php endif; ?><h2><a href="<?= $view->e($base . '/opportunities/' . $task->opportunityId . '/task') ?>"><?= $view->e($task->title) ?></a></h2></div><strong>Priority <?= $view->e(number_format($task->priority, 0)) ?></strong></header>
    <dl class="details"><dt>Assigned to</dt><dd><?= $view->e($task->input->assignee === '' ? 'Unassigned' : ($assignees[$task->input->assignee] ?? 'Unavailable member — reassign')) ?></dd><dt>Due date</dt><dd><?= $view->e($task->input->due ?: 'No due date') ?></dd><dt>SEO finding</dt><dd><?= $view->e(ucfirst($task->findingStatus)) ?></dd><?php if ($task->input->url !== ''): ?><dt>Page</dt><dd><a class="brief-url" href="<?= $view->e($base . '/pages/details?url=' . rawurlencode($task->input->url)) ?>"><?= $view->e($task->input->url) ?></a></dd><?php endif; ?></dl>
    <?php if ($task->input->notes !== ''): ?><p class="task-notes"><?= $view->e(mb_substr($task->input->notes, 0, 300)) ?><?= mb_strlen($task->input->notes) > 300 ? '…' : '' ?></p><?php endif; ?>
    <a class="button button-small button-ghost" href="<?= $view->e($base . '/opportunities/' . $task->opportunityId . '/task') ?>">Open task</a>
</article>
<?php endforeach; ?>
<?php if ($pages > 1): ?><nav class="pagination" aria-label="Task pages"><?php if ($page > 1): ?><a class="button button-ghost" href="<?= $view->e($self . $query($page - 1)) ?>">Previous</a><?php endif; ?><span>Page <?= $view->e($page) ?> of <?= $view->e($pages) ?></span><?php if ($page < $pages): ?><a class="button button-ghost" href="<?= $view->e($self . $query($page + 1)) ?>">Next</a><?php endif; ?></nav><?php endif; ?>
