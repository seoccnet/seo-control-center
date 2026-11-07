<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var string $key
 * @var string $uri
 * @var string|null $error
 */
$view->extend('layouts/app', ['title' => 'Set up two-factor authentication']);
?>
<div class="page-header">
    <h1>Set up two-factor authentication</h1>
</div>
<section class="card">
    <ol class="steps">
        <li>
            In your authenticator app choose <em>Add account</em> → <em>Enter a setup key</em> and enter:
            <p class="setup-key"><code><?= $view->e($key) ?></code></p>
            <p class="muted small">Type: time-based · 6 digits · 30 seconds. On a phone you can instead open <a href="<?= $view->e($uri) ?>">this setup link</a>.</p>
        </li>
        <li>
            Enter the 6-digit code the app shows:
            <?php if ($error !== null): ?>
                <p class="notice notice-error" role="alert"><?= $view->e($error) ?></p>
            <?php endif; ?>
            <form method="post" action="/account/security/mfa/confirm" class="form">
                <?= $view->csrfField() ?>
                <div class="field">
                    <label for="code">Code</label>
                    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required maxlength="7" autofocus>
                </div>
                <div class="actions">
                    <button type="submit" class="button">Turn on</button>
                    <a class="button button-ghost" href="/account/security">Cancel</a>
                </div>
            </form>
        </li>
    </ol>
</section>
