<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var array<string, int> $stats
 * @var list<SeoControlCenter\Core\Queue\FailedJob> $failed
 * @var int $failedTotal
 * @var bool $canManage
 */
$view->extend('layouts/app', ['title' => 'Jobs']);
?>
<div class="page-header">
    <h1>Background jobs</h1>
    <a class="button button-ghost" href="/system/health">System health</a>
</div>

<section class="card">
    <h2>Queue</h2>
    <dl class="settings-summary" aria-label="Queue status">
        <?php foreach (['queued' => 'Queued', 'running' => 'Running', 'completed' => 'Completed', 'failed' => 'Failed'] as $key => $label): ?>
        <div><dt><?= $view->e($label) ?></dt><dd><?= (int) $stats[$key] ?></dd><?php if ($key === 'completed' || $key === 'failed'): ?><span class="small">Kept <?= $key === 'completed' ? '7' : '30' ?> days</span><?php endif; ?></div>
        <?php endforeach; ?>
    </dl>
    <p class="muted">Jobs run with each cron run. A job that fails is retried automatically with increasing delays; after its last attempt it is listed here.</p>
</section>

<section class="card">
    <h2>Failed jobs</h2>
    <?php if ($failed === []): ?>
        <p class="muted">No failed jobs.</p>
    <?php else: ?>
        <?php if ($canManage): ?>
            <form method="post" action="/system/jobs/retry-all">
                <?= $view->csrfField() ?>
                <button type="submit" class="button">Retry all (<?= $failedTotal ?>)</button>
            </form>
            <p class="muted small">Retry after fixing the cause (for example a disconnected integration or an exhausted budget). Retried jobs start with fresh attempts on the next cron run.</p>
        <?php endif; ?>
        <?php if ($failedTotal > count($failed)): ?>
            <p class="muted small">Showing the newest <?= count($failed) ?> of <?= $failedTotal ?>.</p>
        <?php endif; ?>
        <div class="table-scroll"><table class="table table-dense">
            <thead><tr><th scope="col">Failed (UTC)</th><th scope="col">Job</th><th scope="col">Queue</th><th scope="col">Attempts</th><th scope="col">Error</th><?php if ($canManage): ?><th scope="col"><span class="visually-hidden">Actions</span></th><?php endif; ?></tr></thead>
            <tbody>
                <?php foreach ($failed as $job): ?>
                    <tr>
                        <td><?= $view->e($job->failedAt ?? '') ?></td>
                        <td><code><?= $view->e($job->type) ?></code></td>
                        <td><?= $view->e($job->queue) ?></td>
                        <td><?= $job->attempts ?></td>
                        <td class="small"><?= $view->e($job->error ?? '') ?></td>
                        <?php if ($canManage): ?>
                            <td>
                                <div class="actions">
                                    <form method="post" action="/system/jobs/<?= $view->e($job->publicId) ?>/retry">
                                        <?= $view->csrfField() ?>
                                        <button type="submit" class="button button-small">Retry</button>
                                    </form>
                                    <form method="post" action="/system/jobs/<?= $view->e($job->publicId) ?>/dismiss">
                                        <?= $view->csrfField() ?>
                                        <button type="submit" class="button button-small button-ghost">Dismiss</button>
                                    </form>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
