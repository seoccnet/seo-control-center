<?php

use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\PriorityBand;

$rows = array_slice($evidence['ctrActions'], 0, $opportunities !== [] ? 3 : 5);
$rangeDays = max(1, $evidence['range']->days());
?>
<section class="card overview-priorities" aria-labelledby="overview-actions-heading">
    <div class="card-header"><div><p class="integration-kicker">Do next</p><h2 id="overview-actions-heading">Where to act next</h2></div><a class="button button-ghost button-small" href="<?= $view->e($base) ?>/opportunities"><?= $openOpportunities ?> open findings<?= $view->icon('arrow-right') ?></a></div>
    <?php if ($rows !== [] || $opportunities !== []): ?><?= $view->partial('partials/priority-legend', ['caption' => 'Importance']) ?><?php endif; ?>
    <?php if ($rows !== []): ?>
    <h3 class="overview-subheading">Queries that could earn more clicks</h3>
    <ol class="overview-action-list">
    <?php foreach ($rows as $row): $m = $row['metrics']; $band = PriorityBand::ofImpact(Opportunity::impactFromClicks($row['extraClicks'] * 30 / $rangeDays)); ?>
        <li class="<?= $view->e($band->cssClass()) ?>"><div class="overview-action-main"><strong><?= $view->e($row['key']) ?></strong><span class="overview-cell-note"><?= number_format($m->impressions) ?> impressions · <?= $m->clicks ?> clicks · position <?= number_format($m->position, 1) ?></span><span class="overview-cell-note">CTR <?= number_format($m->ctr() * 100, 1) ?> % vs <?= number_format($row['baseline'] * 100, 1) ?> % for <?= $row['peers'] ?> peers (<?= number_format($row['peerImpressions']) ?> impressions)</span></div>
        <div class="overview-action-gain"><span class="priority-pill"><span class="priority-dot" aria-hidden="true"></span><?= $view->e($band->label()) ?></span><strong>+<?= number_format($row['extraClicks'], 1) ?></strong><span class="overview-cell-note">Estimated click gap</span></div>
        <a class="overview-action-link" href="<?= $view->e($base . '/performance?range=' . $rangeKey) ?>">Review query</a></li>
    <?php endforeach; ?>
    </ol>
    <?php endif; ?>
    <?php if ($opportunities !== []): ?>
    <h3 class="overview-subheading">Saved findings</h3>
    <ol class="overview-finding-list">
    <?php foreach (array_slice($opportunities, 0, $rows === [] ? 5 : 2) as $finding): $band = PriorityBand::of($finding->priority); ?>
    <li class="<?= $view->e($band->cssClass()) ?>"><div><span class="tag"><?= $view->e($finding->type->label()) ?></span><h3><a href="<?= $view->e($base . '/opportunities?type=' . $finding->type->value) ?>"><?= $view->e($finding->title) ?></a></h3><p class="small"><?= $view->e($finding->explanation) ?></p><p class="muted small"><?php foreach (array_slice($finding->evidence, 0, 4) as $index => $item): ?><?= $index > 0 ? ' · ' : '' ?><?= $view->e($item['label'] . ': ' . $item['value']) ?><?php endforeach; ?></p></div><div class="overview-finding-meta"><span class="priority-pill"><span class="priority-dot" aria-hidden="true"></span><?= $view->e($band->label()) ?> priority</span><strong>Priority <?= number_format($finding->priority, 1) ?></strong><span class="small muted">Last detected <?= $view->e($finding->lastDetectedAt) ?></span><a class="small" href="<?= $view->e($base . '/opportunities?type=' . $finding->type->value) ?>">Review finding →</a></div></li>
    <?php endforeach; ?></ol>
    <?php endif; ?>
    <?php if ($rows === [] && $opportunities === []): ?><p class="muted">No saved open findings or supported CTR candidates for this period. This does not establish that the site has no issues.</p><a href="<?= $view->e($base) ?>/opportunities">Review analysis and evidence</a><?php endif; ?>
    <?php if ($rows !== [] || $opportunities !== []): ?>
    <details class="overview-about"><summary><?= $view->icon('info') ?>About these priorities</summary>
        <?php if ($rows !== []): ?><p>CTR candidates are ranked by estimated additional clicks within <?= $view->e($evidence['range']->fromString()) ?> – <?= $view->e($evidence['range']->toString()) ?>. Baseline: other observed Google queries in the same position band, within the top <?= $evidence['querySample'] ?> imported queries by clicks. Each candidate's CTR is below half the observed peer baseline.</p>
        <p>Estimate = impressions × (peer CTR − query CTR). It assumes the peer CTR is attainable; query intent and search features may differ. This is a prioritization aid, not a forecast. The colour uses the impact score of the estimate per 30 days (100 clicks ≈ medium, 1,000 ≈ very high).</p><?php endif; ?>
        <?php if ($opportunities !== []): ?><p>Saved findings use the existing analysis priority; scores are not click estimates. Colours follow fixed score bands from green (very low) to red (very high). Last analyzed <?= $view->e($lastAnalyzedAt ?? 'date unavailable') ?> UTC.</p><?php endif; ?>
    </details>
    <?php endif; ?>
</section>
