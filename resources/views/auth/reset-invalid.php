<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 */
$view->extend('layouts/app', ['title' => 'Link not valid']);
?>
<section class="card auth-card">
    <h1>Link not valid</h1>
    <p class="notice notice-error" role="alert">This password-reset link has expired, was already used, or a newer link was requested.</p>
    <p><a href="/forgot-password">Request a new link</a> · <a href="/login">Sign in</a></p>
</section>
