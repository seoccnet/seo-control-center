<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var array<string, string> $errors
 * @var string $name
 */
$view->extend('layouts/app', ['title' => 'New workspace']);
?>
<div class="page-header">
    <h1>New workspace</h1>
</div>

<section class="card">
    <p class="muted">A workspace keeps its own projects, Google and DataForSEO connections, AI access tokens and members, for example one per client or team. You become its owner and can add members afterwards.</p>
        <form method="post" action="/workspaces" class="form" novalidate>
            <?= $view->csrfField() ?>
            <div class="field<?= isset($errors['name']) ? ' has-error' : '' ?>">
                <label for="name">Workspace name</label>
                <input id="name" name="name" type="text" required maxlength="120" placeholder="e.g. Acme Ltd" value="<?= $view->e($name) ?>"<?= $view->invalid($errors, 'name', 'name') ?>>
                <?= $view->fieldError($errors, 'name', 'name') ?>
            </div>
            <div class="actions">
                <button type="submit" class="button">Create workspace</button>
                <a class="button button-ghost" href="/">Cancel</a>
            </div>
        </form>
</section>
