<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var list<array<string, mixed>> $rows
 * @var int|null $nextBefore
 */
$view->extend('layouts/app', ['title' => 'Audit log']);
?>
<div class="page-header">
    <h1>Audit log</h1>
</div>
<section class="card">
    <?php if ($rows === []): ?>
        <p class="muted">No audit events yet.</p>
    <?php else: ?>
        <div class="table-scroll"><table class="table table-dense">
            <thead>
                <tr><th scope="col">Time (UTC)</th><th scope="col">Actor</th><th scope="col">Action</th><th scope="col">Target</th><th scope="col">Result</th><th scope="col">IP</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= $view->e((string) $row['created_at']) ?></td>
                        <td><?= $view->e((string) ($row['actor_label'] ?? $row['actor_type'])) ?></td>
                        <td><code><?= $view->e((string) $row['action']) ?></code></td>
                        <td><?= $view->e(trim((string) ($row['target_type'] ?? '') . ' ' . (string) ($row['target_id'] ?? ''))) ?></td>
                        <td><?= $view->e((string) $row['outcome']) ?></td>
                        <td><?= $view->e((string) ($row['ip_address'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if ($nextBefore !== null): ?>
            <p><a href="/audit?before=<?= $view->e($nextBefore) ?>">Older events</a></p>
        <?php endif; ?>
    <?php endif; ?>
</section>
