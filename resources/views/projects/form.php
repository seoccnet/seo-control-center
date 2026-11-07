<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project|null $project
 * @var array<array-key, mixed> $values
 * @var array<string, string> $errors
 * @var list<string> $timezones
 * @var bool $canDelete
 */
$editing = $project !== null;
$view->extend('layouts/app', $editing
    ? ['title' => 'Settings · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [null, 'Settings']]]
    : ['title' => 'New project', 'crumbs' => [['/projects', 'Projects'], [null, 'New project']]]);
$value = static fn(string $key): string => is_string($values[$key] ?? null) ? $values[$key] : '';
?>
<?php if ($editing): ?>
    <?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'settings']) ?>
<?php else: ?>
    <div class="page-header"><h1>New project</h1></div>
<?php endif; ?>

<div class="manage-workspace manage-settings">
<?php if ($editing): ?><header class="workspace-intro"><p class="portfolio-eyebrow">Manage · Settings</p><h2>Configure your project</h2><p class="muted">Keep project details and search connections together.</p></header><?php endif; ?>
<section class="card">
    <?php if ($editing): ?><h2>Project details</h2><?php endif; ?>
    <?php if ($errors !== []): ?>
        <p class="notice notice-error" role="alert">Please correct the highlighted fields.</p>
    <?php endif; ?>
    <form method="post" action="<?= $editing ? '/projects/' . $view->e($project->publicId) : '/projects' ?>" class="form" novalidate>
        <?= $view->csrfField() ?>
        <div class="field<?= isset($errors['name']) ? ' has-error' : '' ?>">
            <label for="name">Project name</label>
            <input id="name" name="name" type="text" required maxlength="120" value="<?= $view->e($value('name')) ?>"<?= $view->invalid($errors, 'name', 'name') ?>>
            <?= $view->fieldError($errors, 'name', 'name') ?>
        </div>
        <div class="field<?= isset($errors['primary_domain']) ? ' has-error' : '' ?>">
            <label for="primary_domain">Primary domain</label>
            <input id="primary_domain" name="primary_domain" type="text" required maxlength="253" placeholder="example.com" value="<?= $view->e($value('primary_domain')) ?>"<?= $view->invalid($errors, 'primary_domain', 'primary_domain') ?>>
            <?= $view->fieldError($errors, 'primary_domain', 'primary_domain') ?>
        </div>
        <div class="field<?= isset($errors['timezone']) ? ' has-error' : '' ?>">
            <label for="timezone">Reporting time zone</label>
            <select id="timezone" name="timezone"<?= $view->invalid($errors, 'timezone', 'timezone') ?>>
                <?php foreach ($timezones as $tz): ?>
                    <option value="<?= $view->e($tz) ?>"<?= $tz === ($value('timezone') ?: 'UTC') ? ' selected' : '' ?>><?= $view->e($tz) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $view->fieldError($errors, 'timezone', 'timezone') ?>
        </div>
        <div class="actions">
            <button type="submit" class="button"><?= $editing ? 'Save changes' : 'Create project' ?></button>
            <a class="button button-ghost" href="<?= $editing ? '/projects/' . $view->e($project->publicId) : '/projects' ?>">Cancel</a>
        </div>
    </form>
</section>

<?php if ($editing): ?>
    <section class="card">
        <div class="card-header">
            <h2>Google Search Console</h2>
            <a class="button button-ghost button-small" href="/projects/<?= $view->e($project->publicId) ?>/search-console">Manage link</a>
        </div>
        <p class="muted">Which Search Console property feeds this project, and which Google connection it uses.</p>
    </section>
    <section class="card">
        <div class="card-header">
            <h2>Bing Webmaster Tools</h2>
            <a class="button button-ghost button-small" href="/projects/<?= $view->e($project->publicId) ?>/bing">Manage link</a>
        </div>
        <p class="muted">Which Bing Webmaster Tools site feeds this project's Bing performance.</p>
    </section>
    <section class="card">
        <div class="card-header">
            <h2>IndexNow</h2>
            <a class="button button-ghost button-small" href="/projects/<?= $view->e($project->publicId) ?>/indexnow">Manage</a>
        </div>
        <p class="muted">Tell Bing and the other IndexNow engines about new and changed pages, by hand or automatically from your sitemap.</p>
    </section>
    <?php if ($canDelete ?? false): ?>
        <section class="card danger-zone">
            <h2>Delete project</h2>
            <p>The project is hidden immediately. Type its name to confirm.</p>
            <form method="post" action="/projects/<?= $view->e($project->publicId) ?>/delete" class="form">
                <?= $view->csrfField() ?>
                <div class="field">
                    <label for="confirm_name">Project name</label>
                    <input id="confirm_name" name="confirm_name" type="text" required autocomplete="off">
                </div>
                <div class="actions"><button type="submit" class="button button-danger">Delete project</button></div>
            </form>
        </section>
    <?php endif; ?>
<?php endif; ?>
</div>

