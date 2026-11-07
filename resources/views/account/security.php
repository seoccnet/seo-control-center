<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var bool $mfaEnabled
 * @var int $remainingCodes
 * @var bool $encryptionReady
 */
$view->extend('layouts/app', ['title' => 'Account security']);
?>
<div class="page-header">
    <h1>Account security</h1>
    <a class="button button-ghost" href="/account">Name, e-mail and password</a>
</div>

<section class="card">
    <h2>Two-factor authentication</h2>
    <?php if ($mfaEnabled): ?>
        <p><strong>On.</strong> Sign-in asks for a code from your authenticator app.</p>
        <p class="muted">Unused recovery codes: <?= $view->e($remainingCodes) ?><?= $remainingCodes <= 3 ? ' — generate new ones soon.' : '' ?></p>

        <h3>New recovery codes</h3>
        <form method="post" action="/account/security/recovery-codes" class="form">
            <?= $view->csrfField() ?>
            <div class="field">
                <label for="rc-password">Current password</label>
                <input id="rc-password" name="password" type="password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="button button-ghost">Generate new recovery codes</button>
        </form>

        <h3>Turn off</h3>
        <form method="post" action="/account/security/mfa/disable" class="form">
            <?= $view->csrfField() ?>
            <div class="field">
                <label for="off-password">Current password</label>
                <input id="off-password" name="password" type="password" autocomplete="current-password" required>
            </div>
            <div class="field">
                <label for="off-code">Code from your app (or a recovery code)</label>
                <input id="off-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required maxlength="11">
            </div>
            <button type="submit" class="button button-danger">Turn off two-factor authentication</button>
        </form>
    <?php elseif (!$encryptionReady): ?>
        <p class="notice notice-error">Encryption keys are not configured on this server, so two-factor authentication cannot be enabled yet. An administrator must run <code>php bin/console crypto:key:generate</code>.</p>
    <?php else: ?>
        <p><strong>Off.</strong> Protect your account with an authenticator app (for example Google Authenticator, Microsoft Authenticator, 1Password or Bitwarden).</p>
        <form method="post" action="/account/security/mfa/setup">
            <?= $view->csrfField() ?>
            <button type="submit" class="button">Set up two-factor authentication</button>
        </form>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Sessions</h2>
    <p>Sign out everywhere except this browser, for example after using a shared computer.</p>
    <form method="post" action="/account/sessions/logout-others">
        <?= $view->csrfField() ?>
        <button type="submit" class="button button-ghost">Sign out other sessions</button>
    </form>
</section>
