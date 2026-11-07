<section class="card" aria-labelledby="overview-changes-heading">
<div class="card-header"><div><p class="integration-kicker">Changes & context</p><h2 id="overview-changes-heading">Recent SEO changes</h2></div><a class="small" href="<?= $view->e($base) ?>/annotations">Timeline →</a></div>
<?php if ($evidence['changes'] === []): ?><p class="muted">No recorded SEO changes. Add a dated note after an edit to compare saved performance around it.</p><a href="<?= $view->e($base) ?>/annotations">Record a change →</a>
<?php else: ?>
<ul class="overview-change-list"><?php foreach ($evidence['changes'] as $row): $note = $row['note']; $impact = $row['impact']; $clicks = $impact['google_changes']['clicks']; ?>
<li><time datetime="<?= $view->e($note->date) ?>"><?= $view->e($note->date) ?></time><div><h3><a href="<?= $view->e($base . '/annotations/' . $note->publicId . '/impact?days=14') ?>"><?= $view->e($note->title) ?></a></h3><p class="muted small"><?= $view->e($note->url ?? 'Site-wide change') ?></p><p class="small"><?php if ($clicks['available']): ?>Google clicks <?= number_format($impact['before']['google']['clicks']) ?> → <?= number_format($impact['after']['google']['clicks']) ?> <strong>(<?= sprintf('%+d', $clicks['delta']) ?>)</strong><?php else: ?>Comparison not ready: complete before/after evidence is required.<?php endif; ?></p><p class="muted small"><?= $view->e($impact['before']['from'] . ' – ' . $impact['before']['to'] . ' vs ' . $impact['after']['from'] . ' – ' . $impact['after']['to']) ?></p></div></li>
<?php endforeach; ?></ul>
<details class="overview-about"><summary><?= $view->icon('info') ?>About this data</summary><p>Latest three notes, including notes outside the selected search period. Equal 14-day windows exclude the change day. Movement does not establish causation.</p></details>
<?php endif; ?>
</section>
