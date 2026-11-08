<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var list<array{name: string, ok: bool, detail: string}> $checks
 * @var array<string, string> $errors
 * @var array<string, string> $values
 * @var bool $setupAuthorized
 * @var string $setupKeyPath
 */
$view->extend('layouts/install', ['title' => 'Set up your workspace']);
$allOk = !in_array(false, array_column($checks, 'ok'), true);
$field = static function (string $name, string $label, string $type = 'text', string $help = '', string $autocomplete = 'off', string $constraints = ' required') use ($view, $errors, $values): string {
    $value = $type === 'password' ? '' : ($values[$name] ?? '');
    $html = '<div class="field' . (isset($errors[$name]) ? ' has-error' : '') . '">';
    $html .= '<label for="' . $view->e($name) . '">' . $view->e($label) . '</label>';
    $html .= '<input id="' . $view->e($name) . '" name="' . $view->e($name) . '" type="' . $view->e($type) . '" value="' . $view->e($value) . '" autocomplete="' . $view->e($autocomplete) . '"' . ($help !== '' ? ' aria-describedby="' . $view->e($name) . '-help"' : '') . $view->invalid($errors, $name, $name) . $constraints . '>';
    if ($help !== '') {
        $html .= '<p class="muted small" id="' . $view->e($name) . '-help">' . $help . '</p>';
    }
    return $html . $view->fieldError($errors, $name, $name) . '</div>';
};
?>
<div class="installer-intro"><p class="installer-eyebrow">Your subdomain. Your SEO workspace.</p><h1>Let’s get you set up.</h1><p>A few details, then you’re ready to sign in. Keep the subdomain folder cPanel created — there is no domain path to change.</p></div>
<div class="installer-layout" data-installer>
<nav class="installer-index" aria-label="Setup progress"><p class="installer-eyebrow">Your installation</p><ol>
<?php foreach (['Welcome', 'Site & access', 'Database', 'Your account', 'Review & install'] as $index => $label): ?>
<li><a href="#setup-step-<?= $index ?>" data-step-link="<?= $index ?>"><?= $view->e($label) ?></a></li>
<?php endforeach; ?>
</ol><p class="muted small">Need help with cPanel?<br><a href="https://github.com/seoccnet/seo-control-center/blob/main/docs/guide/02-installation.md" target="_blank" rel="noopener noreferrer">Open the installation guide ↗</a></p></nav>
<div class="installer-content">
<?php foreach (['general', 'requirements'] as $key): ?>
<?php if (isset($errors[$key])): ?><p class="notice notice-error" role="alert"><?= $view->e($errors[$key]) ?></p><?php endif; ?>
<?php endforeach; ?>
<?php if ($errors !== []): ?><p class="notice" role="status">Please correct the highlighted details. For your privacy, enter passwords again before retrying.</p><?php endif; ?>
<p class="installer-progress small muted" data-step-status aria-live="polite" hidden></p>
<form method="post" action="/install" class="form install-form" novalidate data-install-form>
<section class="card installer-section" id="setup-step-0" data-setup-step aria-labelledby="welcome-heading" tabindex="-1">
<div class="installer-section-heading"><span aria-hidden="true">1</span><div><h2 id="welcome-heading">Welcome to SEO Control Center</h2><p>We’ll check the hosting before you enter your details.</p></div><span class="badge badge-<?= $allOk ? 'ok' : 'error' ?>"><?= $allOk ? 'Ready' : 'Needs attention' ?></span></div>
<div class="installer-welcome"><strong>Have these ready</strong><ul><li>The empty database name, database user and password from cPanel.</li><li>Your email and a new password for your SEOcc account.</li><li>Your agency or company name for the first workspace.</li></ul></div>
<details class="installer-requirements"<?= $allOk ? '' : ' open' ?>><summary><?= $allOk ? 'All hosting checks passed — view details' : 'See what needs fixing' ?></summary><ul class="installer-checks">
<?php foreach ($checks as $check): ?><li><div><strong><?= $view->e($check['name']) ?></strong><p><?= $view->e($check['detail']) ?></p></div><span class="badge badge-<?= $check['ok'] ? 'ok' : 'error' ?>"><?= $check['ok'] ? 'Ready' : 'Fix' ?></span></li><?php endforeach; ?>
</ul></details>
<?php if (!$allOk): ?><p class="notice notice-error">Fix the failed checks in cPanel, then <a href="/install">check again</a>. Your host can help enable PHP or missing extensions.</p><?php endif; ?>
</section>
<section class="card installer-section" id="setup-step-1" data-setup-step aria-labelledby="site-heading" tabindex="-1">
<h2 id="site-heading">Your site and setup access</h2><p class="muted">SEOcc runs on its own subdomain, such as seo.example.com.</p>
<?php if ($setupAuthorized): ?>
<p class="notice notice-success">Your launcher verified setup access. You can continue without copying another code.</p><input type="hidden" name="setup_key" value="<?= $view->e($values['setup_key']) ?>">
<?php else: ?>
<?= $field('setup_key', 'Server setup key', 'text', 'In cPanel File Manager, open <code>' . $view->e($setupKeyPath) . '</code> and copy the key.', 'off', ' required pattern="[a-fA-F0-9]{32}" maxlength="32"') ?>
<?php endif; ?>
<?= $field('app_url', 'Your subdomain address', 'url', 'Your HTTPS subdomain address, without a folder path. We filled it in from this page.') ?>
<input type="hidden" name="environment" value="production"><p class="muted small">Production setup.</p>
</section>
<section class="card installer-section" id="setup-step-2" data-setup-step aria-labelledby="database-heading" tabindex="-1">
<h2 id="database-heading">Connect your empty database</h2><p class="muted">In cPanel, use <strong>Database Wizard</strong> to create a database and user, then select <strong>ALL PRIVILEGES</strong>.</p>
<div class="installer-fields">
<?= $field('db_name', 'Full database name', 'text', 'Include the cPanel prefix, for example <code>youruser_seocc</code>.') ?>
<?= $field('db_user', 'Full database user', 'text', 'Copy the complete user name from cPanel, including its prefix.') ?>
<?= $field('db_password', 'Database password', 'password', 'The password you generated for this database user in cPanel.', 'off') ?>
</div><details class="installer-advanced"<?= isset($errors['db_host']) || isset($errors['db_port']) ? ' open' : '' ?>><summary>Database host and port</summary><p class="muted small">Usually localhost and 3306. Change these only if your host gave you different values.</p><div class="installer-fields">
<?= $field('db_host', 'Database host') ?>
<?= $field('db_port', 'Database port', 'number', '', 'off', ' required min="1" max="65535"') ?>
</div></details><p class="muted small">The final install checks the connection before creating SEOcc tables. Use a dedicated empty database.</p>
</section>
<section class="card installer-section" id="setup-step-3" data-setup-step aria-labelledby="account-heading" tabindex="-1">
<h2 id="account-heading">Create your administrator account</h2><p class="muted">These are your SEOcc sign-in details. You can invite teammates later.</p><div class="installer-fields">
<?= $field('admin_name', 'Your name', 'text', '', 'name', ' required maxlength="120"') ?>
<?= $field('admin_email', 'Your email', 'email', '', 'username') ?>
<?= $field('admin_password', 'Your SEOcc password', 'password', 'Use at least 12 characters.', 'new-password', ' required minlength="12"') ?>
<?= $field('admin_password_confirm', 'Repeat your SEOcc password', 'password', '', 'new-password', ' required minlength="12"') ?>
<?= $field('workspace', 'Workspace name', 'text', 'Usually your agency or company name.', 'organization', ' required maxlength="120"') ?>
</div></section>
<section class="card installer-section" id="setup-step-4" data-setup-step aria-labelledby="review-heading" tabindex="-1">
<h2 id="review-heading">Ready to create your workspace?</h2><p class="muted">We’ll connect the database, create your account and lock the installer.</p>
<dl class="installer-review" data-review hidden><div><dt>Site</dt><dd data-review-field="app_url"></dd></div><div><dt>Database</dt><dd data-review-field="db_name"></dd></div><div><dt>Administrator</dt><dd data-review-field="admin_email"></dd></div><div><dt>Workspace</dt><dd data-review-field="workspace"></dd></div></dl>
<div class="field"><label for="cron_interval">How often should background jobs run?</label><select name="cron_interval" id="cron_interval" aria-describedby="cron_interval-help">
<?php foreach (['1' => 'Every minute', '5' => 'Every 5 minutes — recommended', '10' => 'Every 10 minutes', '15' => 'Every 15 minutes', '30' => 'Every 30 minutes', '60' => 'Every hour'] as $value => $label): ?><option value="<?= $value ?>"<?= ($values['cron_interval'] ?? '5') === (string) $value ? ' selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?>
</select><p class="muted small" id="cron_interval-help">Choose the interval your host allows. We’ll save it and give you the matching cPanel command after installation.</p><?= $view->fieldError($errors, 'cron_interval', 'cron_interval') ?></div>
<p class="notice">Keep this page open while setup runs. Your passwords stay out of this review.</p>
<div class="installer-submit"><button type="submit" class="button"<?= $allOk ? '' : ' disabled' ?>>Install SEO Control Center</button><span class="muted small" data-install-state aria-live="polite"></span></div>
</section>
<div class="installer-step-actions" data-step-actions hidden><button type="button" class="button button-ghost" data-step-back>Back</button><span class="muted small" data-step-count></span><button type="button" class="button" data-step-next<?= $allOk ? '' : ' disabled' ?>>Continue</button></div>
</form>
</div></div>

