<?php

declare(strict_types=1);

?>
<?php $deviceLabel = ($record['form_factor'] ?? ($formFactor ?? 'PHONE')) === 'DESKTOP' ? 'Desktop' : 'Mobile'; ?>
<h3><?= $view->e($deviceLabel) ?> Core Web Vitals</h3>
<?php if ($record === null): ?><p>No field measurement fetched yet. Configure CrUX under Integrations, then request a check for this URL.</p>
<?php elseif ($record['status'] === 'no_data'): ?><p>CrUX has no <?= $view->e(strtolower($deviceLabel)) ?> field data for this URL. This does not mean it passes. Checked <?= $view->e($record['fetched_at']) ?> UTC.</p>
<?php else: ?>
<p>75th percentile, <?= $view->e(strtolower($deviceLabel)) ?> real-user data: <?= $view->e($record['period_from'] ?? '?') ?> to <?= $view->e($record['period_to'] ?? '?') ?>. Fetched <?= $view->e($record['fetched_at']) ?> UTC.</p>
<dl><dt>LCP (good ≤ 2,500 ms)</dt><dd><?= $view->e($record['lcp_ms'] ?? 'Unavailable') ?> ms</dd><dt>INP (good ≤ 200 ms)</dt><dd><?= $view->e($record['inp_ms'] ?? 'Unavailable') ?> ms</dd><dt>CLS (good ≤ 0.1)</dt><dd><?= $view->e($record['cls'] ?? 'Unavailable') ?></dd></dl>
<?php endif; ?>
