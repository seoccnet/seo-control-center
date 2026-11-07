<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var list<string> $codes
 * @var bool $justEnabled
 */
$view->extend('layouts/app', ['title' => 'Recovery codes']);
?>
<div class="page-header">
    <h1>Recovery codes</h1>
</div>
<section class="card">
    <?php if ($justEnabled): ?>
        <p class="notice notice-success" role="status">Two-factor authentication is on.</p>
    <?php endif; ?>
    <p>Store these codes somewhere safe, such as your password manager. Each code works once if you lose access to your authenticator app. <strong>They will not be shown again.</strong> Any older codes no longer work.</p>
    <ul class="recovery-codes">
        <?php foreach ($codes as $code): ?>
            <li><code><?= $view->e($code) ?></code></li>
        <?php endforeach; ?>
    </ul>
    <p><a class="button" href="/account/security">Done</a></p>
</section>
