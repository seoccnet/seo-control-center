<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Core\Mail\MailSettings $settings
 * @var bool $hasPassword
 * @var array<string, mixed>|null $old
 * @var array<string, string> $errors
 * @var string $adminEmail
 */
$view->extend('layouts/app', ['title' => 'E-mail']);
$value = static function (string $key, ?string $saved) use ($old): string {
    if ($old !== null) {
        return is_string($old[$key] ?? null) ? $old[$key] : '';
    }

    return $saved ?? '';
};
$transport = $value('transport', $settings->transport);
$encryption = $value('encryption', $settings->encryption);
$transports = [
    'none' => ['Off', 'No e-mails are sent. "Forgot password" and e-mailed invitations are unavailable.'],
    'smtp' => ['SMTP server', 'Recommended. Use the mailbox of your hosting (cPanel → Email Accounts → Connect Devices shows the settings) or a mail service.'],
    'php' => ['PHP mail()', 'The hosting\'s built-in mail function. Simple, but messages more often land in spam.'],
];
?>
<div class="page-header">
    <h1>E-mail</h1>
</div>

<section class="card">
    <p class="muted"><?= $view->e(isset($brand) ? $brand()->name($appName ?? '') : 'SEO Control Center') ?> sends e-mails for password resets, member invitations and reports. Settings apply to the whole installation.</p>
    <?php if ($errors !== []): ?>
        <p class="notice notice-error" role="alert">Please correct the highlighted fields.</p>
    <?php endif; ?>
    <form method="post" action="/system/email" class="form" novalidate>
        <?= $view->csrfField() ?>
        <fieldset class="field<?= isset($errors['transport']) ? ' has-error' : '' ?>"<?= isset($errors['transport']) ? ' aria-describedby="transport-error"' : '' ?>>
            <legend>Send e-mails with</legend>
            <?php foreach ($transports as $key => [$label, $hint]): ?>
                <label><input type="radio" name="transport" value="<?= $view->e($key) ?>"<?= $transport === $key ? ' checked' : '' ?>> <strong><?= $view->e($label) ?></strong> <span class="muted small"><?= $view->e($hint) ?></span></label>
            <?php endforeach; ?>
            <?= $view->fieldError($errors, 'transport', 'transport') ?>
        </fieldset>

        <h2>SMTP server</h2>
        <div class="field<?= isset($errors['host']) ? ' has-error' : '' ?>">
            <label for="host">Server</label>
            <input id="host" name="host" type="text" maxlength="253" placeholder="mail.example.com" autocomplete="off" value="<?= $view->e($value('host', $settings->host)) ?>"<?= $view->invalid($errors, 'host', 'host') ?>>
            <?= $view->fieldError($errors, 'host', 'host') ?>
        </div>
        <div class="field<?= isset($errors['encryption']) ? ' has-error' : '' ?>">
            <label for="encryption">Encryption</label>
            <select id="encryption" name="encryption"<?= $view->invalid($errors, 'encryption', 'encryption') ?>>
                <option value="tls"<?= $encryption === 'tls' ? ' selected' : '' ?>>STARTTLS (usually port 587)</option>
                <option value="ssl"<?= $encryption === 'ssl' ? ' selected' : '' ?>>SSL/TLS (usually port 465)</option>
                <option value="none"<?= $encryption === 'none' ? ' selected' : '' ?>>None (not recommended)</option>
            </select>
            <?= $view->fieldError($errors, 'encryption', 'encryption') ?>
        </div>
        <div class="field<?= isset($errors['port']) ? ' has-error' : '' ?>">
            <label for="port">Port <span class="muted">(empty for the default)</span></label>
            <input id="port" name="port" type="text" inputmode="numeric" maxlength="5" value="<?= $view->e($value('port', $settings->port !== null ? (string) $settings->port : null)) ?>"<?= $view->invalid($errors, 'port', 'port') ?>>
            <?= $view->fieldError($errors, 'port', 'port') ?>
        </div>
        <div class="field">
            <label for="username">User name</label>
            <input id="username" name="username" type="text" maxlength="255" autocomplete="off" placeholder="noreply@example.com" value="<?= $view->e($value('username', $settings->username)) ?>">
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" maxlength="1024" autocomplete="new-password"<?= $hasPassword ? ' placeholder="Saved; leave empty to keep it"' : '' ?>>
            <?php if ($hasPassword): ?>
                <label class="small"><input type="checkbox" name="clear_password" value="1"> Remove the saved password</label>
            <?php endif; ?>
            <p class="muted small">Stored encrypted. It is never shown again.</p>
        </div>

        <h2>Sender</h2>
        <div class="field<?= isset($errors['from_address']) ? ' has-error' : '' ?>">
            <label for="from_address">Sender address</label>
            <input id="from_address" name="from_address" type="email" maxlength="191" placeholder="noreply@example.com" value="<?= $view->e($value('from_address', $settings->fromAddress)) ?>"<?= $view->invalid($errors, 'from_address', 'from_address') ?>>
            <?= $view->fieldError($errors, 'from_address', 'from_address') ?>
            <p class="muted small">Use an address of your own domain, ideally the SMTP mailbox itself, so the messages are not marked as spam.</p>
        </div>
        <div class="field<?= isset($errors['from_name']) ? ' has-error' : '' ?>">
            <label for="from_name">Sender name</label>
            <input id="from_name" name="from_name" type="text" maxlength="120" placeholder="SEO Control Center" value="<?= $view->e($value('from_name', $settings->fromName)) ?>"<?= $view->invalid($errors, 'from_name', 'from_name') ?>>
            <?= $view->fieldError($errors, 'from_name', 'from_name') ?>
        </div>
        <div class="actions"><button type="submit" class="button">Save settings</button></div>
    </form>
</section>

<section class="card">
    <h2>Test</h2>
    <?php if ($settings->isConfigured()): ?>
        <p class="muted">Sends a short message with the saved settings to <strong><?= $view->e($adminEmail) ?></strong>.</p>
        <form method="post" action="/system/email/test" class="form">
            <?= $view->csrfField() ?>
            <div class="actions"><button type="submit" class="button button-ghost">Send test e-mail</button></div>
        </form>
    <?php else: ?>
        <p class="muted">Save working settings first.</p>
    <?php endif; ?>
</section>
