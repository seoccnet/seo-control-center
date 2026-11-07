<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var string $email
 * @var array<string, string> $errors
 */
$view->extend('layouts/app', ['title' => 'Choose a new password']);
?>
<section class="card auth-card">
    <h1>Choose a new password</h1>
    <p class="muted">For <strong><?= $view->e($email) ?></strong>. After saving, all sessions of this account are signed out.</p>
    <form method="post" class="form" novalidate>
        <?= $view->csrfField() ?>
        <div class="field<?= isset($errors['password']) ? ' has-error' : '' ?>">
            <label for="password">New password</label>
            <input id="password" name="password" type="password" required maxlength="1024" autocomplete="new-password"<?= $view->invalid($errors, 'password', 'password') ?>>
            <?= $view->fieldError($errors, 'password', 'password') ?>
        </div>
        <div class="field<?= isset($errors['password_confirmation']) ? ' has-error' : '' ?>">
            <label for="password_confirmation">Repeat new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required maxlength="1024" autocomplete="new-password"<?= $view->invalid($errors, 'password_confirmation', 'password_confirmation') ?>>
            <?= $view->fieldError($errors, 'password_confirmation', 'password_confirmation') ?>
        </div>
        <button type="submit" class="button">Save new password</button>
    </form>
</section>
