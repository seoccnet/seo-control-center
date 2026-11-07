<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var int $status
 * @var string $message
 * @var string|null $requestId
 * @var Throwable|null $exception  only set when debug output is allowed
 */
$view->extend('layouts/app', ['title' => 'Error ' . $status]);
?>
<section class="card">
    <h1>Error <?= $view->e($status) ?></h1>
    <p><?= $view->e($message) ?></p>
    <?php if ($requestId !== null): ?>
        <p class="muted">Request ID: <code><?= $view->e($requestId) ?></code></p>
    <?php endif; ?>
    <p><a href="/">Back to the start page</a></p>

    <?php if ($exception !== null): ?>
        <details class="debug" open>
            <summary>Debug details (APP_DEBUG, not shown in production)</summary>
            <p><strong><?= $view->e($exception::class) ?></strong>: <?= $view->e($exception->getMessage()) ?></p>
            <p><code><?= $view->e($exception->getFile()) ?>:<?= $view->e($exception->getLine()) ?></code></p>
            <pre><?= $view->e($exception->getTraceAsString()) ?></pre>
        </details>
    <?php endif; ?>
</section>
