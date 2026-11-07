<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Modules\Bing\BingSiteLink|null $link
 * @var list<string> $sites
 * @var bool $canManage
 * @var string|null $error
 */
$view->extend('layouts/app', ['title' => 'Bing · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], ['/projects/' . $project->publicId . '/edit', 'Settings'], [null, 'Bing']]]);
$base = '/projects/' . $project->publicId;
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'settings']) ?>

<?= $view->partial('partials/integration-guide', ['guide' => 'bing', 'open' => false]) ?>

<section class="card">
    <?php if ($link !== null): ?>
        <p>Linked Bing site: <strong><?= $view->e($link->siteUrl) ?></strong></p>
        <p class="muted small">Linked <?= $view->e($link->linkedAt) ?> UTC<?= $link->lastSuccessAt !== null ? ' · last import ' . $view->e($link->lastSuccessAt) . ' UTC' : '' ?></p>
        <?php if ($link->status === 'error' && $link->lastError !== null): ?>
            <p class="notice notice-error"><?= $view->e($link->lastError) ?></p>
        <?php endif; ?>
        <p><a class="button button-ghost button-small" href="<?= $view->e($base) ?>/performance/bing">Bing performance</a></p>
        <?php if ($canManage): ?>
            <form method="post" action="<?= $view->e($base) ?>/bing/unlink">
                <?= $view->csrfField() ?>
                <button type="submit" class="button button-small button-ghost">Unlink</button>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <p>No Bing site is linked to this project yet.</p>
    <?php endif; ?>
</section>

<?php if ($canManage): ?>
    <section class="card">
        <h2><?= $link === null ? 'Link a site' : 'Change site' ?></h2>
        <?php if ($error !== null): ?>
            <p class="notice notice-error" role="alert"><?= $view->e($error) ?></p>
        <?php endif; ?>
        <?php if ($sites === []): ?>
            <p class="muted">No verified Bing site is available. <a href="/integrations/bing">Connect Bing Webmaster Tools</a> and verify your site there first.</p>
        <?php else: ?>
            <form method="post" action="<?= $view->e($base) ?>/bing" class="form">
                <?= $view->csrfField() ?>
                <div class="field">
                    <label for="site">Site</label>
                    <select id="site" name="site" required>
                        <?php foreach ($sites as $site): ?>
                            <option value="<?= $view->e($site) ?>"<?= $link !== null && $link->siteUrl === $site ? ' selected' : '' ?>><?= $view->e($site) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <p class="muted small">Changing the site deletes the Bing data imported for the previous one.</p>
                <button type="submit" class="button">Link site</button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
