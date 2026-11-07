<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var array{connection: SeoControlCenter\Domain\Integrations\Connection, property: SeoControlCenter\Domain\SearchConsole\SearchConsoleProperty, linked_at: string}|null $link
 * @var list<array{connection: SeoControlCenter\Domain\Integrations\Connection, properties: list<SeoControlCenter\Domain\SearchConsole\SearchConsoleProperty>}> $options
 * @var bool $canManage
 * @var string|null $error
 */
$view->extend('layouts/app', ['title' => 'Search Console · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], ['/projects/' . $project->publicId . '/edit', 'Settings'], [null, 'Search Console']]]);
$base = '/projects/' . $project->publicId;
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'settings']) ?>

<?= $view->partial('partials/integration-guide', ['guide' => 'google', 'open' => false]) ?>

<section class="card">
    <?php if ($link !== null): ?>
        <p>Linked property: <strong><?= $view->e($link['property']->label()) ?></strong></p>
        <p class="muted small">Via Google account <?= $view->e($link['connection']->accountEmail) ?> · linked <?= $view->e($link['linked_at']) ?> UTC</p>
        <?php if (!$link['connection']->isActive()): ?>
            <p class="notice notice-error">The Google connection needs to be reconnected under Integrations.</p>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <form method="post" action="<?= $view->e($base) ?>/search-console/unlink">
                <?= $view->csrfField() ?>
                <button type="submit" class="button button-small button-ghost">Unlink</button>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <p>No Search Console property is linked to this project yet.</p>
    <?php endif; ?>
</section>

<?php if ($canManage): ?>
    <section class="card">
        <h2><?= $link === null ? 'Link a property' : 'Change property' ?></h2>
        <?php if ($error !== null): ?>
            <p class="notice notice-error" role="alert"><?= $view->e($error) ?></p>
        <?php endif; ?>
        <?php if ($options === []): ?>
            <p class="muted">No Google account is connected in this workspace. <a href="/integrations">Connect one under Integrations</a>.</p>
        <?php else: ?>
            <form method="post" action="<?= $view->e($base) ?>/search-console" class="form">
                <?= $view->csrfField() ?>
                <div class="field">
                    <label for="property">Property</label>
                    <select id="property" name="property" required>
                        <?php foreach ($options as ['connection' => $connection, 'properties' => $properties]): ?>
                            <optgroup label="<?= $view->e($connection->accountEmail) ?>">
                                <?php foreach ($properties as $property): ?>
                                    <?php $value = $connection->publicId . '|' . $property->siteUrl; ?>
                                    <option value="<?= $view->e($value) ?>"<?= $link !== null && $link['property']->siteUrl === $property->siteUrl ? ' selected' : '' ?>><?= $view->e($property->label()) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="button">Link property</button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
