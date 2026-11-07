<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var string|null $error
 */
$view->extend('layouts/app', ['title' => 'Two-factor verification']);
?>
<section class="card auth-card">
    <h1>Verify it's you</h1>
    <p class="muted">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>

    <?php if ($error !== null): ?>
        <p class="notice notice-error" role="alert"><?= $view->e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="/login/verify" class="form">
        <?= $view->csrfField() ?>
        <div class="field">
            <label for="code">Code</label>
            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required maxlength="11" autofocus>
        </div>
        <button type="submit" class="button">Verify</button>
    </form>
    <p class="small"><a href="/login">Start over</a></p>
</section>
