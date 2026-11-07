<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var list<SeoControlCenter\Modules\System\Health\HealthCheck> $checks
 * @var list<string> $pendingMigrations
 * @var bool $canUpdate
 */
$view->extend('layouts/app', ['title' => 'System health']);

$groups = [];
foreach ($checks as $check) {
    $groups[$check->group][] = $check;
}
$labels = ['ok' => 'OK', 'warning' => 'Warning', 'error' => 'Problem', 'info' => 'Info'];
$problems = count(array_filter($checks, static fn($c): bool => $c->status === 'error'));
$warnings = count(array_filter($checks, static fn($c): bool => $c->status === 'warning'));
?>
<div class="page-header">
    <h1>System health</h1>
    <div class="actions">
        <a class="button button-ghost" href="/system/jobs">Jobs</a>
        <?php if ($canUpdate): ?><a class="button button-ghost" href="/system/backups">Backups</a><?php endif; ?>
        <a class="button button-ghost" href="/system/support-bundle">Download support bundle</a>
    </div>
</div>

<p class="notice <?= $problems > 0 ? 'notice-error' : 'notice-success' ?>" role="status">
    <?= $problems === 0 && $warnings === 0 ? 'Everything looks good.' : $view->e(sprintf('%d problem(s), %d warning(s).', $problems, $warnings)) ?>
</p>

<dl class="settings-summary" aria-label="Health check summary">
    <div><dt>Checks</dt><dd><?= count($checks) ?></dd></div>
    <div><dt>Problems</dt><dd><?= $problems ?></dd></div>
    <div><dt>Warnings</dt><dd><?= $warnings ?></dd></div>
    <div><dt>Pending database updates</dt><dd><?= count($pendingMigrations) ?></dd></div>
</dl>

<?php if ($pendingMigrations !== []): ?>
    <section class="card">
        <h2>Database updates</h2>
        <p class="notice notice-error"><?= $view->e(count($pendingMigrations)) ?> database update(s) are waiting — the application may not work correctly until they are applied.</p>
        <ul class="muted small">
            <?php foreach ($pendingMigrations as $name): ?><li><code><?= $view->e($name) ?></code></li><?php endforeach; ?>
        </ul>
        <?php if ($canUpdate): ?>
            <form method="post" action="/system/migrations">
                <?= $view->csrfField() ?>
                <button type="submit" class="button">Apply database updates</button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php foreach ($groups as $group => $items): ?>
    <section class="card">
        <h2><?= $view->e($group) ?></h2>
        <div class="table-scroll"><table class="table">
            <tbody>
                <?php foreach ($items as $check): ?>
                    <tr>
                        <th scope="row" class="health-name"><?= $view->e($check->name) ?></th>
                        <td class="health-status"><span class="badge badge-<?= $view->e($check->status) ?>"><?= $view->e($labels[$check->status] ?? $check->status) ?></span></td>
                        <td><?= $view->e($check->detail) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
<?php endforeach; ?>
