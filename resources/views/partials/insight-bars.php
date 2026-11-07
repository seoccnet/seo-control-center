<?php
/** Ranked values supplied by the page; widths are relative to the largest listed value. */
$maximum = max([1, ...array_column($bars, 'value')]);
?>
<section class="card insight-bars"><div class="card-header"><div><p class="portfolio-eyebrow"><?= $view->e($eyebrow ?? 'Breakdown') ?></p><h2><?= $view->e($title) ?></h2></div></div><p class="muted small"><?= $view->e($note) ?></p>
<?php if ($bars === []): ?><p class="muted">No imported data for this breakdown yet.</p><?php else: ?><ol class="insight-bar-list"><?php foreach ($bars as $bar): ?><li><div><span class="insight-bar-label"><?= $view->e($bar['label']) ?></span><strong><?= $view->e($bar['display'] ?? number_format($bar['value'])) ?></strong></div><progress value="<?= $view->e(max(0, $bar['value'])) ?>" max="<?= $view->e($maximum) ?>" aria-label="<?= $view->e($bar['label']) ?>"></progress></li><?php endforeach; ?></ol><?php endif; ?></section>
