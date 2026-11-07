<?php

declare(strict_types=1);
?>
<p class="muted small">Up to 100 newest reviews, including original text when supplied and the owner’s reply. A sample, not a complete review archive. Standard-queue tasks may take up to 45 minutes.</p>
<?php if (isset($state['status'])): ?><p>Import: <?= $view->e($state['status']) ?><?= isset($state['fetched_at']) ? ' · ' . $view->e($state['fetched_at']) . ' UTC' : '' ?></p><?php endif; ?>
<?php if (isset($state['error'])): ?><p role="alert"><?= $view->e($state['error']) ?></p><?php endif; ?>
<?php foreach ($state['items'] ?? [] as $review): ?><article class="module-review"><h4><?= $view->e($review['author'] ?: 'Reviewer') ?> · <?= $view->e($review['rating'] ?? '—') ?> / 5</h4><p class="muted small"><?= $view->e($review['published'] ?? '') ?></p><p><?= nl2br($view->e($review['text'])) ?></p><?php if ($review['reply'] !== null): ?><p><strong>Owner reply:</strong> <?= nl2br($view->e($review['reply'])) ?></p><?php endif; ?></article><?php endforeach; ?>
<?php if (($state['items'] ?? []) === []): ?><p>No review texts imported.</p><?php endif; ?>
