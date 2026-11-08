<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var array{email: string, url: string, environment: string} $result
 * @var string $phpHint
 * @var string $cronPath
 * @var string $keyPath
 * @var string $cronInterval
 */
$view->extend('layouts/install', ['title' => 'Your workspace is ready']);
$command = '/opt/cpanel/ea-php' . $phpHint . "/root/usr/bin/php '" . str_replace("'", "'\\''", $cronPath) . "' >/dev/null 2>&1";
$schedule = $cronInterval === '60' ? '0 * * * *' : ($cronInterval === '1' ? '* * * * *' : '*/' . $cronInterval . ' * * * *');
?>
<section class="card installer-complete">
<p class="installer-eyebrow">Your workspace is ready</p><h1>You’re installed. Let’s finish up.</h1>
<p class="notice notice-success" role="status">Your administrator account <strong><?= $view->e($result['email']) ?></strong> is ready. The installer is locked.</p>
<ol class="installer-next-steps">
<li><h2>Enable background jobs in cPanel</h2><p>Open <strong>Cron Jobs</strong>. Choose <?= $cronInterval === '60' ? 'once per hour' : 'every ' . $view->e($cronInterval) . ($cronInterval === '1' ? ' minute' : ' minutes') ?>. We saved this interval in your settings.</p>
<div class="installer-copy"><label for="cron-schedule">Schedule (minute · hour · day · month · weekday)</label><input id="cron-schedule" value="<?= $view->e($schedule) ?>" readonly><button type="button" class="button button-ghost" data-copy="cron-schedule" hidden>Copy schedule</button></div>
<div class="installer-copy"><label for="cron-command">Command for this installation</label><textarea id="cron-command" rows="3" readonly><?= $view->e($command) ?></textarea><button type="button" class="button button-ghost" data-copy="cron-command" hidden>Copy command</button></div>
<p class="muted small">The application path above is already filled in. The PHP executable shown is the standard cPanel path for PHP <?= $view->e(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?>. If your host uses a different PHP path, ask them for the correct command-line executable.</p><p>Click <strong>Add New Cron Job</strong>. After <?= $view->e((int) $cronInterval + 2) ?> minutes, check <strong>System → Health → Cron</strong> in SEOcc.</p><a href="https://github.com/seoccnet/seo-control-center/blob/main/docs/guide/02-installation.md" target="_blank" rel="noopener noreferrer">See the installation guide ↗</a></li>
<li><h2>Keep your encryption key safe</h2><p>In File Manager, download <code><?= $view->e($keyPath) ?></code> and keep an offline copy. Database backups do not include this key.</p></li>
<li><h2>Sign in</h2><p>Use the email above and the password you just created.</p><p><a class="button" href="/login">Sign in to your workspace</a></p></li>
</ol><p class="muted small">Next: configure System → E-mail, send a test message, then add your first project.</p>
</section>

