<?php $local = $evidence['local']; ?>
<section class="card overview-half" aria-labelledby="overview-local-heading"><div class="card-header"><div><p class="integration-kicker">Local search</p><h2 id="overview-local-heading">Local coverage</h2></div><a class="small" href="<?= $view->e($base) ?>/local">Local SEO →</a></div>
<p><?= $view->e($local['settings']->businessName) ?></p>
<?php if ($local['date'] === null): ?><p class="muted">No saved local checks. Run a local check from Local SEO.</p>
<?php else: ?><p><strong><?= $local['top3'] ?> / <?= $local['sample'] ?></strong> checked keywords in the map top three.</p><p class="muted small"><?= $view->e($local['date']) ?>.<?php if ($local['delta'] !== null): ?> <?= sprintf('%+d', $local['delta']) ?> top-three keywords among <?= $local['common'] ?> keywords checked on both dates; previous <?= $view->e($local['previousDate']) ?>.<?php endif; ?></p><?php endif; ?>
<?php foreach ($local['profiles'] as $snapshot): $profile = $snapshot['profile']; ?><p class="small"><?= $view->e($snapshot['date']) ?> · rating <?= $profile->rating === null ? 'unavailable' : number_format($profile->rating, 1) ?> · <?= $profile->votes === null ? 'review count unavailable' : number_format($profile->votes) . ' reviews' ?></p><?php endforeach; ?>
<details class="overview-about"><summary><?= $view->icon('info') ?>About this data</summary><p>Primary location's stored keyword and business-profile samples.</p></details>
</section>
