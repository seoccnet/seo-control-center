<?php
/**
 * An MCP sign-in request that cannot be answered safely (unknown app or redirect address).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var string $message
 */
$view->extend('layouts/app', ['title' => 'Connection not possible']);
?>
<div class="page-header">
    <h1>Connection not possible</h1>
</div>
<section class="card">
    <p class="notice notice-error" role="alert"><?= $view->e($message) ?></p>
    <p class="muted">Go back to the AI app and add the connection again. The server address is shown under Integrations → AI access.</p>
</section>
