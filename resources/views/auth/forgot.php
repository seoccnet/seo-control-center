<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var bool $available
 * @var string $email
 * @var string|null $error
 * @var bool $sent
 * @var int $minutes
 */
$view->extend('layouts/app', ['title' => 'Forgot password']);
?>
<section class="card auth-card">
    <h1>Forgot your password?</h1>
    <?php if (!$available): ?>
        <p class="muted">Password reset by e-mail is not set up on this installation. Ask your administrator to set a new password for you, or to set up e-mail under <em>System → E-mail</em>.</p>
    <?php elseif ($sent): ?>
        <p class="notice notice-success" role="status">If an account exists for this address, we have sent a link to choose a new password. It is valid for <?= $view->e($minutes) ?> minutes. Check your spam folder if it does not arrive.</p>
    <?php else: ?>
        <p class="muted">Enter the e-mail address you sign in with. We will send you a link to choose a new password.</p>
        <?php if ($error !== null): ?>
            <p class="notice notice-error" role="alert"><?= $view->e($error) ?></p>
        <?php endif; ?>
        <form method="post" action="/forgot-password" class="form">
            <?= $view->csrfField() ?>
            <div class="field">
                <label for="email">E-mail address</label>
                <input id="email" name="email" type="email" autocomplete="username" required maxlength="191" value="<?= $view->e($email) ?>" autofocus>
            </div>
            <button type="submit" class="button">Send link</button>
        </form>
    <?php endif; ?>
    <p class="small"><a href="/login">Back to sign-in</a></p>
</section>
