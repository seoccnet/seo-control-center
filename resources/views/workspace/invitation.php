<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Workspaces\Invitation $invitation
 * @var bool $newAccount
 * @var string $roleLabel
 * @var array<string, string> $errors
 * @var string $name
 */
$view->extend('layouts/app', ['title' => 'Join ' . $invitation->workspaceName]);
?>
<section class="card auth-card">
    <h1>Join <?= $view->e($invitation->workspaceName) ?></h1>
    <p class="muted">
        <?= $invitation->invitedByName !== null ? $view->e($invitation->invitedByName) . ' invited you' : 'You were invited' ?>
        as <strong><?= $view->e($roleLabel) ?></strong>. The invitation is for <strong><?= $view->e($invitation->email) ?></strong> and expires on <?= $view->e($invitation->expiresAt) ?> UTC.
    </p>

    <?php if (isset($errors['email'])): ?>
        <p class="notice notice-error" role="alert"><?= $view->e($errors['email']) ?></p>
    <?php endif; ?>

    <form method="post" class="form" novalidate>
        <?= $view->csrfField() ?>
        <?php if ($newAccount): ?>
            <p class="muted small">Create your account to accept.</p>
            <div class="field<?= isset($errors['name']) ? ' has-error' : '' ?>">
                <label for="name">Your name</label>
                <input id="name" name="name" type="text" maxlength="120" required autocomplete="name" value="<?= $view->e($name) ?>"<?= $view->invalid($errors, 'name', 'name') ?>>
                <?= $view->fieldError($errors, 'name', 'name') ?>
            </div>
            <div class="field<?= isset($errors['password']) ? ' has-error' : '' ?>">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" maxlength="1024" required autocomplete="new-password"<?= $view->invalid($errors, 'password', 'password') ?>>
                <?= $view->fieldError($errors, 'password', 'password') ?>
            </div>
            <div class="field<?= isset($errors['password_confirmation']) ? ' has-error' : '' ?>">
                <label for="password_confirmation">Repeat password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" maxlength="1024" required autocomplete="new-password"<?= $view->invalid($errors, 'password_confirmation', 'password_confirmation') ?>>
                <?= $view->fieldError($errors, 'password_confirmation', 'password_confirmation') ?>
            </div>
            <button type="submit" class="button">Create account and join</button>
        <?php else: ?>
            <p class="muted small">This address already has an account. Join, then sign in with your existing password.</p>
            <button type="submit" class="button">Join workspace</button>
        <?php endif; ?>
    </form>
</section>
