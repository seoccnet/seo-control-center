<?php $ranking = $evidence['ranking']; ?>
<section class="card overview-side" aria-labelledby="overview-buckets-heading">
<div class="card-header"><div><p class="integration-kicker">Tracked rankings</p><h2 id="overview-buckets-heading">Where keywords rank</h2></div></div>
<?php if ($ranking['date'] === null): ?><p class="muted">No saved rank checks yet.</p><a href="<?= $view->e($base) ?>/rankings">Set up rank tracking →</a>
<?php else: ?>
<p class="muted small"><?= $view->e($ranking['date'] . ' · ' . $ranking['device']) ?> · <?= $ranking['sample'] ?> checked keywords</p>
<table class="table table-dense" data-no-table-controls><thead><tr><th scope="col">Position</th><th scope="col" class="num">Now</th><th scope="col" class="num">Δ same cohort</th></tr></thead><tbody>
<?php foreach ($ranking['counts'] as $label => $count): ?><tr><th scope="row"><span class="overview-rank-label<?= $label === '1–3' ? ' overview-rank-leading' : ($label === '4–10' ? ' overview-rank-visible' : '') ?>"><?= $view->e($label) ?></span></th><td class="num"><strong><?= $count ?></strong><span class="overview-cell-note"><?= $ranking['sample'] > 0 ? number_format($count / $ranking['sample'] * 100, 1) : '0' ?> %</span></td><td class="num"><?= $ranking['common'] > 0 ? sprintf('%+d', $ranking['matched'][$label] - $ranking['prior'][$label]) : '—' ?></td></tr><?php endforeach; ?>
</tbody></table>
<a href="<?= $view->e($base) ?>/rankings">Review rankings →</a>
<details class="overview-about"><summary><?= $view->icon('info') ?>About this data</summary><p>Not found = checked but absent from the first 100 results. <?= $ranking['unrepresented'] ?> tracked keywords have no snapshot on this date.</p><?php if ($ranking['common'] > 0): ?><p>Change compares <?= $ranking['common'] ?> keywords checked on both <?= $view->e($ranking['previousDate']) ?> and <?= $view->e($ranking['date']) ?>. Counts above include all latest-day checks.</p><?php else: ?><p>No shared checked cohort for comparison.</p><?php endif; ?></details>
<?php endif; ?>
</section>
