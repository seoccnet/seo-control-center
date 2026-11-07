<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var string $email
 * @var string|null $error
 * @var string $appName
 */
$view->extend('layouts/app', ['title' => 'Sign in']);
?>
<section class="card auth-card">
    <h1>Sign in</h1>
    <p class="muted">Welcome back. Sign in to continue.</p>

    <?php if ($error !== null): ?>
        <p class="notice notice-error" role="alert"><?= $view->e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="/login" class="form">
        <?= $view->csrfField() ?>
        <div class="field">
            <label for="email">E-mail address</label>
            <input id="email" name="email" type="email" autocomplete="username" required maxlength="191" value="<?= $view->e($email) ?>" autofocus>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="1024">
        </div>
        <button type="submit" class="button">Sign in</button>
    </form>
    <p class="small"><a href="/forgot-password">Forgot your password?</a></p>
</section>
