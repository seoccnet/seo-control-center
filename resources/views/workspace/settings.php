<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Workspaces\Workspace $workspace
 * @var bool $canRename
 * @var bool $canDelete
 * @var string|null $deleteProblem why the workspace cannot be deleted, or null
 * @var array<string, string> $errors
 * @var array<string, mixed> $old
 */
$view->extend('layouts/app', ['title' => 'Workspace settings']);
$name = is_string($old['name'] ?? null) ? $old['name'] : $workspace->name;
?>
<div class="page-header">
    <h1>Workspace settings</h1>
</div>

<section class="card">
    <h2>Name</h2>
    <?php if ($canRename): ?>
        <form method="post" action="/workspace/settings" class="form" novalidate>
            <?= $view->csrfField() ?>
            <div class="field<?= isset($errors['name']) ? ' has-error' : '' ?>">
                <label for="name">Workspace name</label>
                <input id="name" name="name" type="text" required maxlength="120" value="<?= $view->e($name) ?>"<?= $view->invalid($errors, 'name', 'name') ?>>
                <?= $view->fieldError($errors, 'name', 'name') ?>
            </div>
            <div class="actions"><button type="submit" class="button">Save</button></div>
        </form>
    <?php else: ?>
        <p><?= $view->e($workspace->name) ?></p>
        <p class="muted small">Only owners and admins can rename the workspace.</p>
    <?php endif; ?>
</section>

<?php if ($canDelete): ?>
    <section class="card danger-zone">
        <h2>Delete workspace</h2>
        <?php if ($deleteProblem !== null && !isset($errors['confirm'])): ?>
            <p class="muted"><?= $view->e($deleteProblem) ?></p>
        <?php else: ?>
        <p>Members lose access immediately, and all projects of the workspace are deleted with it, together with their scheduled imports, rank checks and AI access tokens. Type the workspace name <strong><?= $view->e($workspace->name) ?></strong> to confirm.</p>
        <form method="post" action="/workspace/delete" class="form" novalidate>
            <?= $view->csrfField() ?>
            <div class="field<?= isset($errors['confirm']) ? ' has-error' : '' ?>">
                <label for="confirm">Workspace name</label>
                <input id="confirm" name="confirm" type="text" required autocomplete="off"<?= $view->invalid($errors, 'confirm', 'confirm') ?>>
                <?= $view->fieldError($errors, 'confirm', 'confirm') ?>
            </div>
            <div class="actions"><button type="submit" class="button button-danger">Delete workspace</button></div>
        </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
