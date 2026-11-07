<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var list<SeoControlCenter\Core\Backup\BackupFile> $backups
 */
$view->extend('layouts/app', ['title' => 'Backups']);
$size = static fn(int $bytes): string => $bytes >= 1048576 ? sprintf('%.1f MB', $bytes / 1048576) : sprintf('%.0f KB', max(1, $bytes / 1024));
?>
<div class="page-header">
    <h1>Backups</h1>
    <a class="button button-ghost" href="/system/health">System health</a>
</div>

<section class="card">
    <h2>Database backups</h2>
    <p class="muted">A backup contains the whole database as compressed SQL, stored in <code>storage/backups</code> (outside the web root). The newest backups are kept; older ones are deleted automatically.</p>
    <p class="notice notice-error">Backups do <strong>not</strong> contain the encryption key ring (<code>storage/keys</code> or <code>APP_KEYS</code>). Keep an offline copy of it: without it, stored passwords and tokens in a restored database cannot be decrypted.</p>
    <form class="backup-actions" method="post" action="/system/backups">
        <?= $view->csrfField() ?>
        <button type="submit" class="button">Create backup now</button>
    </form>
    <p class="muted small">Large databases can take a few minutes. On the command line: <code>php bin/console backup:create</code>. Restoring replaces the whole database and is only possible on the command line: <code>php bin/console backup:restore &lt;file&gt; --force</code>.</p>
</section>

<section class="card">
    <h2>Available backups</h2>
    <?php if ($backups === []): ?>
        <p class="muted">No backups yet.</p>
    <?php else: ?>
        <div class="table-scroll"><table class="table table-dense numeric">
            <thead><tr><th scope="col">File</th><th scope="col">Size</th><th scope="col">Created (UTC)</th><th scope="col"><span class="visually-hidden">Download</span></th></tr></thead>
            <tbody>
                <?php foreach ($backups as $backup): ?>
                    <tr>
                        <td><code><?= $view->e($backup->name) ?></code></td>
                        <td><?= $view->e($size($backup->size)) ?></td>
                        <td><?= $view->e($backup->createdAt) ?></td>
                        <td class="backup-download"><a class="button button-small button-ghost" href="/system/backups/<?= $view->e($backup->name) ?>"><?= $view->icon('download') ?>Download</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
