<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var string $currentVersion
 * @var SeoControlCenter\Core\Update\ReleaseManifest|null $pending
 * @var string|null $pendingError
 * @var array<string, mixed>|null $state
 * @var list<array<string, mixed>> $history
 * @var int $uploadLimitMb
 * @var bool $keyConfigured
 */
$view->extend('layouts/app', ['title' => 'Updates']);
?>
<div class="page-header">
    <h1>Updates</h1>
    <a class="button button-ghost" href="/system/backups">Backups</a>
</div>

<section class="card">
    <h2>Installed version</h2>
    <p><strong><?= $view->e($currentVersion) ?></strong></p>
    <p class="muted">Updates are signed release packages (<code>scc-&lt;version&gt;.zip</code>). A package is only accepted when its signature matches this installation's public key, it is newer than the installed version and every file matches the signed list. Your <code>.env</code>, <code>storage</code> and uploaded data are never touched.</p>
    <?php if (!$keyConfigured): ?>
        <p class="notice notice-error">No update public key is configured (<code>UPDATE_PUBLIC_KEY</code>), so no package can be verified.</p>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Where to get updates</h2>
    <p>Download <code>scc-&lt;version&gt;.zip</code> from <a href="<?= $view->e($sourceUrl . '/releases') ?>" target="_blank" rel="noopener noreferrer">the project's releases</a> and upload it below. This installation never contacts a vendor server to look for updates, and nothing is installed automatically.</p>
    <p class="muted small">If you build your own packages, sign them with your own key and set <code>UPDATE_PUBLIC_KEY</code>.</p>
</section>

<?php if (is_array($state) && ($state['status'] ?? '') !== 'completed'): ?>
    <section class="card">
        <h2>Last update attempt</h2>
        <p class="notice <?= ($state['status'] ?? '') === 'failed' ? 'notice-error' : '' ?>">
            Version <?= $view->e((string) ($state['version'] ?? '?')) ?>:
            <?= $view->e((string) ($state['status'] ?? '?')) ?> at step “<?= $view->e((string) ($state['step'] ?? '?')) ?>”
            (<?= $view->e((string) ($state['at'] ?? '')) ?> UTC)<?php if (($state['error'] ?? null) !== null): ?> — <?= $view->e((string) $state['error']) ?><?php endif; ?>
        </p>
        <?php if (($state['status'] ?? '') === 'failed'): ?>
            <p class="muted">If file replacement began, the site stays in maintenance mode. Retry the same signed package to resume database updates, even if the installed version already shows the new release. The original database backup is protected under Backups until recovery succeeds. Keep the pending package; restoring requires both the original database and matching application files.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($pending !== null): ?>
    <section class="card">
        <h2>Ready to install: <?= $view->e($pending->version) ?></h2>
        <p>Signature verified · <?= count($pending->files) ?> files · built <?= $view->e($pending->createdAt) ?> · requires PHP <?= $view->e($pending->minimumPhp) ?> or newer.</p>
        <p class="muted">Installing switches the site to maintenance mode, takes a consistent database backup, replaces the changed files, runs database updates and switches maintenance mode off again. During the active installation, pages temporarily show a maintenance notice. It usually takes under a minute; keep this tab open.</p>
        <p class="notice" role="note"><strong>Always back up before installing.</strong> Save a recent database backup, your application files and an offline copy of the encryption keys. The updater creates a database backup, but it does not create a complete hosting backup. Use System → Backups and your hosting panel before continuing.</p>
        <div class="actions">
            <form method="post" action="/system/updates/install">
                <?= $view->csrfField() ?>
                <label class="checkbox"><input type="checkbox" name="backup_confirmed" value="1" required> I have a current backup of the database, application files and encryption keys.</label>
                <button type="submit" class="button">Install <?= $view->e($pending->version) ?></button>
            </form>
            <form method="post" action="/system/updates/discard">
                <?= $view->csrfField() ?>
                <button type="submit" class="button button-ghost">Discard package</button>
            </form>
        </div>
    </section>
<?php else: ?>
    <section class="card">
        <h2>Upload a package</h2>
        <?php if ($pendingError !== null): ?>
            <p class="notice notice-error">The uploaded package cannot be installed: <?= $view->e($pendingError) ?></p>
            <form method="post" action="/system/updates/discard">
                <?= $view->csrfField() ?>
                <button type="submit" class="button button-ghost">Remove it</button>
            </form>
        <?php endif; ?>
        <form method="post" action="/system/updates/upload" enctype="multipart/form-data">
            <?= $view->csrfField() ?>
            <label for="package">Release package (.zip)</label>
            <input type="file" id="package" name="package" accept=".zip,application/zip" required>
            <p class="muted small">Upload limit on this server: <?= $uploadLimitMb ?> MB. If a package is larger, raise <code>upload_max_filesize</code> and <code>post_max_size</code> in the PHP settings of your hosting panel.</p>
            <button type="submit" class="button">Upload and verify</button>
        </form>
    </section>
<?php endif; ?>

<section class="card">
    <h2>History</h2>
    <?php if ($history === []): ?>
        <p class="muted">No updates installed through this page yet.</p>
    <?php else: ?>
        <table class="table table-dense">
            <thead><tr><th scope="col">Finished (UTC)</th><th scope="col">From</th><th scope="col">To</th><th scope="col">Result</th><th scope="col">Details</th></tr></thead>
            <tbody>
                <?php foreach ($history as $row): ?>
                    <tr>
                        <td><?= $view->e((string) $row['finished_at']) ?></td>
                        <td><?= $view->e((string) $row['from_version']) ?></td>
                        <td><?= $view->e((string) $row['to_version']) ?></td>
                        <td><?= $view->e((string) $row['status']) ?></td>
                        <td class="small"><?= $view->e((string) ($row['message'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
