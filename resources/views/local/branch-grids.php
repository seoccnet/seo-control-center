<?php

declare(strict_types=1);

use SeoControlCenter\Domain\Local\LocalGrid;

$tone = static fn(int|false|null $p): string => match (true) { $p === false => 'grid-pending', $p === null => 'grid-none', $p <= 3 => 'grid-top', $p <= 10 => 'grid-mid', default => 'grid-low' };
?>
<div<?= $data['queued'] ? ' data-refresh-seconds="15"' : '' ?>>
<h3>Branch visibility grids</h3>
<p class="muted small">Exact CID matching at each search point. Green: top 3; amber: 4–10; orange: 11–20; red: not found; dot: pending. North is up. Grids run only when requested and use the provider budget.</p>
<?php if ($canRun && $available && $featureOn): ?>
<form method="post" action="<?= $view->e($path . '/grid') ?>" class="filter-form">
    <?= $view->csrfField() ?>
    <label class="filter-field"><span>Branch keyword</span><select name="grid_keyword"><?php foreach ($location->keywords as $keyword): ?><option><?= $view->e($keyword) ?></option><?php endforeach; ?></select></label>
    <label class="filter-field"><span>Grid size and estimated cost</span><select name="grid_size"><?php foreach (LocalGrid::SIZES as $size): ?><option value="<?= $size ?>"><?= $size ?> × <?= $size ?> · ≈ $<?= $view->e(number_format($data['costs'][$size], 4)) ?></option><?php endforeach; ?></select></label>
    <label class="filter-field"><span>Point spacing</span><select name="grid_spacing"><?php foreach (LocalGrid::SPACINGS as $metres => $label): ?><option value="<?= $metres ?>"><?= $view->e($label) ?></option><?php endforeach; ?></select></label>
    <button class="button button-small" type="submit"<?= $data['queued'] ? ' disabled' : '' ?>>Queue paid branch grid</button>
</form>
<?php endif; ?>
<?php if ($data['queued']): ?><p role="status">A grid is queued; cron fills one point at a time.</p><?php endif; ?>
<?php if ($data['grids'] === []): ?><p class="muted">No branch grids yet.</p><?php endif; ?>
<div class="grid-list">
<?php foreach ($data['grids'] as $b): $g = $b->grid; ?>
<figure class="grid-card"><figcaption><strong><?= $view->e($b->keyword) ?></strong><span class="muted small"><?= $view->e($g->checkedOn . ' · ' . $g->status . ' · ' . $g->spacing . ' m apart') ?></span></figcaption>
<div class="rank-grid rank-grid-<?= $g->size ?>" role="img" aria-label="<?= $view->e(sprintf('%s: %d of %d points checked, found at %d points', $b->keyword, count($g->positions), $g->points(), $g->found())) ?>">
<?php foreach ($g->rows() as $r => $cells): foreach ($cells as $c => $p): ?><span class="grid-cell <?= $tone($p) ?><?= $r === intdiv($g->size, 2) && $c === intdiv($g->size, 2) ? ' grid-center' : '' ?>"><?= $p === false ? '·' : ($p === null ? '–' : $p) ?></span><?php endforeach; endforeach; ?>
</div><p class="muted small"><?= count($g->positions) ?>/<?= $g->points() ?> points checked · average <?= $view->e($g->averagePosition() ?? '—') ?></p>
<?php if ($b->error !== null): ?><p class="notice notice-warning" role="status"><?= $view->e($b->error) ?></p><?php endif; ?></figure>
<?php endforeach; ?></div></div>
