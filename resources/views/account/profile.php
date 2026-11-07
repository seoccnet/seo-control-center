<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var string $name
 * @var string $email
 * @var string $currentEmail
 * @var array<string, string> $errors
 */
$view->extend('layouts/app', ['title' => 'Your account']);
?>
<div class="page-header">
    <h1>Your account</h1>
    <div class="page-actions">
        <a class="button button-ghost" href="/account/notifications">Notifications</a>
        <a class="button button-ghost" href="/account/security">Two-factor sign-in and sessions</a>
    </div>
</div>

<section class="card">
    <h2>Profile</h2>
    <form method="post" action="/account/profile" class="form" novalidate>
        <?= $view->csrfField() ?>
        <div class="field<?= isset($errors['name']) ? ' has-error' : '' ?>">
            <label for="name">Name</label>
            <input id="name" name="name" type="text" required maxlength="120" autocomplete="name" value="<?= $view->e($name) ?>"<?= $view->invalid($errors, 'name', 'name') ?>>
            <?= $view->fieldError($errors, 'name', 'name') ?>
        </div>
        <div class="field<?= isset($errors['email']) ? ' has-error' : '' ?>">
            <label for="email">E-mail address</label>
            <input id="email" name="email" type="email" required maxlength="191" autocomplete="email" value="<?= $view->e($email) ?>"<?= $view->invalid($errors, 'email', 'email') ?>>
            <?= $view->fieldError($errors, 'email', 'email') ?>
            <p class="muted small">You sign in with this address. Currently: <?= $view->e($currentEmail) ?></p>
        </div>
        <div class="field<?= isset($errors['current_password_profile']) ? ' has-error' : '' ?>">
            <label for="current_password_profile">Current password <span class="muted">(only when changing the e-mail address)</span></label>
            <input id="current_password_profile" name="current_password_profile" type="password" maxlength="1024" autocomplete="current-password"<?= $view->invalid($errors, 'current_password_profile', 'current_password_profile') ?>>
            <?= $view->fieldError($errors, 'current_password_profile', 'current_password_profile') ?>
        </div>
        <div class="actions"><button type="submit" class="button">Save profile</button></div>
    </form>
</section>

<section class="card">
    <h2>Password</h2>
    <p class="muted">After the change, all your other sessions are signed out; this one stays signed in.</p>
    <form method="post" action="/account/password" class="form" novalidate>
        <?= $view->csrfField() ?>
        <div class="field<?= isset($errors['current_password']) ? ' has-error' : '' ?>">
            <label for="current_password">Current password</label>
            <input id="current_password" name="current_password" type="password" required maxlength="1024" autocomplete="current-password"<?= $view->invalid($errors, 'current_password', 'current_password') ?>>
            <?= $view->fieldError($errors, 'current_password', 'current_password') ?>
        </div>
        <div class="field<?= isset($errors['new_password']) ? ' has-error' : '' ?>">
            <label for="new_password">New password</label>
            <input id="new_password" name="new_password" type="password" required maxlength="1024" autocomplete="new-password"<?= $view->invalid($errors, 'new_password', 'new_password') ?>>
            <?= $view->fieldError($errors, 'new_password', 'new_password') ?>
        </div>
        <div class="field<?= isset($errors['new_password_confirmation']) ? ' has-error' : '' ?>">
            <label for="new_password_confirmation">Repeat new password</label>
            <input id="new_password_confirmation" name="new_password_confirmation" type="password" required maxlength="1024" autocomplete="new-password"<?= $view->invalid($errors, 'new_password_confirmation', 'new_password_confirmation') ?>>
            <?= $view->fieldError($errors, 'new_password_confirmation', 'new_password_confirmation') ?>
        </div>
        <div class="actions"><button type="submit" class="button">Change password</button></div>
    </form>
</section>
