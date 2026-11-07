<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var bool $throttled
 */
$view->extend('layouts/app', ['title' => 'Invitation']);
?>
<section class="card auth-card">
    <h1>Invitation</h1>
    <?php if ($throttled): ?>
        <p class="notice notice-error" role="alert">Too many attempts. Wait 15 minutes and try again.</p>
    <?php else: ?>
        <p class="notice notice-error" role="alert">This invitation link is not valid. It may have expired, been used already, or been revoked.</p>
        <p class="muted">Ask the person who invited you for a new link.</p>
    <?php endif; ?>
    <p><a href="/login">Go to sign-in</a></p>
</section>
