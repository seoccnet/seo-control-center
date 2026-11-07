<?php

declare(strict_types=1);

/**
 * Manage → Change impact → measurement plan of one SEO change (ADR-042, 0.39.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var array<string, mixed> $measurement MeasurementPlanService::plan()
 * @var bool $canEdit
 */

use SeoControlCenter\Domain\Measurement\MeasurementResult;
use SeoControlCenter\Modules\Annotations\MeasurementPlanService;

$plan = $measurement['plan'];
$result = $measurement['result'];
$annotation = $measurement['annotation'];
$action = '/projects/' . $project->publicId . '/annotations/' . $annotation->publicId . '/plan';
$badge = static fn(string $status): string => match ($status) {
    'improved', 'reached' => 'badge badge-ok',
    'declined' => 'badge badge-warning',
    'insufficient_evidence' => 'badge badge-warning',
    default => 'badge',
};
$number = static fn(float $v, string $metric): string => match ($metric) {
    'ctr' => number_format($v, 2) . ' %',
    'position' => number_format($v, 1),
    'conversions' => number_format($v, 1),
    default => number_format($v),
};
$value = $plan ?? [...$measurement['defaults'], 'objective' => '', 'target_queries' => [], 'control_pages' => [], 'goal' => null, 'version' => 0];
?>
<section class="card" id="measurement-plan" aria-labelledby="measurement-plan-title">
    <div class="card-header"><h2 id="measurement-plan-title">Measurement plan</h2><?php if ($result !== null): ?><span class="<?= $badge($result['status']) ?>"><?= $view->e($result['label']) ?><?= $result['attribution'] === 'controlled' ? ' · controlled' : '' ?></span><?php endif; ?></div>
    <p class="muted">Decide before the results come in what this change should move and how it will be judged. The baseline is frozen with how complete each source was. A result is read only when every gate below passes; until then the plan stays planned or pending. Without control pages, a change is an association, never proof of cause.</p>

    <?php if ($plan === null): ?>
    <p class="muted small">No plan yet.<?= $canEdit ? ' Fill in the form below.' : '' ?></p>
    <?php else: ?>
    <p><strong>Objective:</strong> <?= $view->e($plan['objective']) ?></p>
    <p class="small muted">Primary metric: <?= $view->e(MeasurementResult::METRICS[$plan['metric']] ?? $plan['metric']) ?><?= $plan['goal'] !== null ? ' · goal: ' . $view->e(($plan['metric'] === 'position' ? 'at most ' : 'at least ') . $number($plan['goal'], $plan['metric'])) . ' in the ' . (int) $plan['days'] . ' days after the change (no baseline needed)' : '' ?> · <?= (int) $plan['days'] ?> days before and after, change day excluded · targets: <?= $plan['target_pages'] === [] && $plan['target_queries'] === [] ? 'the whole property' : $view->e(count($plan['target_pages']) . ' page(s), ' . count($plan['target_queries']) . ' query(ies)') ?> · controls: <?= count($plan['control_pages']) ?> page(s) · reviews <?= $view->e(implode(', ', $plan['review_dates'])) ?><?= $measurement['next_review'] !== null ? ' (next ' . $view->e($measurement['next_review']) . ')' : '' ?> in <?= $view->e($plan['timezone']) ?>. Search Console days are Pacific Time.</p>

    <?php if ($result !== null): ?>
    <h3>Result</h3>
    <p><strong><?= $view->e($result['label']) ?>.</strong> <?= $view->e($result['reason']) ?></p>
    <ul class="small measurement-gates"><?php foreach ($result['gates'] as $gate): ?><li><?= $gate['passed'] ? '<span class="badge badge-ok">Passed</span>' : '<span class="badge">Not met</span>' ?> <?= $view->e($gate['label']) ?>: <?= $view->e($gate['detail']) ?></li><?php endforeach; ?></ul>
    <?php if ($result['goal'] !== null): ?><p class="small muted">Measured <?= $view->e($number($result['goal']['value'], $plan['metric'])) ?> against the goal of <?= $view->e($number($result['goal']['goal'], $plan['metric'])) ?>.</p><?php endif; ?>
    <?php if ($result['target'] !== null): ?>
    <div class="table-scroll"><table class="table table-dense" data-no-table-controls><caption class="small muted"><?= $view->e(MeasurementResult::METRICS[$plan['metric']] ?? $plan['metric']) ?>, frozen baseline against the period after the change</caption>
        <thead><tr><th scope="col">Group</th><th scope="col" class="num">Before</th><th scope="col" class="num">After</th><th scope="col" class="num">Change</th></tr></thead>
        <tbody>
        <?php foreach (['Targets' => $result['target'], 'Control pages' => $result['control']] as $label => $side): if ($side === null) { continue; } ?>
            <tr><th scope="row"><?= $view->e($label) ?></th><td class="num"><?= $view->e($number($side['before'], $plan['metric'])) ?></td><td class="num"><?= $view->e($number($side['after'], $plan['metric'])) ?></td><td class="num"><?= $view->e(sprintf('%+.1f', $side['change']) . ($result['unit'] === '%' ? ' %' : ' positions')) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="small muted">Effect<?= $result['control'] !== null ? ' beyond the control pages' : '' ?>: <?= $view->e(sprintf('%+.1f', (float) $result['effect']) . ($result['unit'] === '%' ? ' percentage points' : ' positions (positive is better)')) ?>. Smaller than <?= $view->e($plan['metric'] === 'position' ? MeasurementResult::POSITION_THRESHOLD . ' positions' : MeasurementResult::THRESHOLD . ' %') ?> reads as no clear change.</p>
    <?php endif; ?>
    <?php endif; ?>

    <h3>Baseline</h3>
    <?php if ($plan['baseline'] === null): ?>
        <p class="small muted">Not frozen. Freeze it once the <?= (int) $plan['days'] ?> days before the change are imported. Changing the metric, targets, controls or period clears it.</p>
    <?php else: $b = $plan['baseline']['targets']; ?>
        <?php if (\SeoControlCenter\Modules\Annotations\ResultsLedgerService::afterwards($annotation, $plan['frozen_at'])): ?><p class="small muted">Measured afterwards: this baseline was frozen after the change, so the plan was not set before its results. Treat the result as a rough guide.</p><?php endif; ?>
        <p class="small">Frozen <?= $view->e((string) $plan['frozen_at']) ?> UTC for <?= $view->e($b['from'] . ' → ' . $b['to']) ?>: Search Console <?= (int) $b['google_days'] ?> of <?= (int) $b['expected_days'] ?> days, GA4 <?= (int) $b['analytics_days'] ?> of <?= (int) $b['expected_days'] ?> days<?= $b['missing'] !== [] ? '; no data for ' . $view->e(implode(', ', $b['missing'])) : '' ?>.</p>
    <?php endif; ?>
    <?php if ($canEdit): ?><form method="post" action="<?= $view->e($action . '/freeze') ?>"><?= $view->csrfField() ?><input type="hidden" name="version" value="<?= (int) $plan['version'] ?>"><button class="button button-secondary button-small"><?= $plan['baseline'] === null ? 'Freeze baseline' : 'Freeze again' ?></button></form><?php endif; ?>

    <?php if ($measurement['concurrent'] !== []): ?>
    <h3>Other changes in these periods</h3>
    <ul class="small"><?php foreach (array_slice($measurement['concurrent'], 0, 10) as $other): ?><li><?= $view->e($other->date . ' · ' . $other->title) ?></li><?php endforeach; ?></ul>
    <p class="small muted">They may explain part of any change. No listed change does not mean nothing else changed.</p>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <details class="measurement-form"<?= $plan === null ? ' open' : '' ?>><summary class="button button-small button-secondary"><?= $plan === null ? 'Create the plan' : 'Edit the plan' ?></summary>
    <form method="post" action="<?= $view->e($action) ?>" class="stack"><?= $view->csrfField() ?><input type="hidden" name="version" value="<?= (int) $value['version'] ?>">
        <div class="field"><label for="plan-objective">Objective</label><textarea id="plan-objective" name="objective" rows="2" maxlength="1000" required><?= $view->e($value['objective']) ?></textarea></div>
        <div class="inline-form"><label for="plan-metric" class="small">Primary metric</label><select id="plan-metric" name="metric"><?php foreach (MeasurementResult::METRICS as $key => $label): ?><option value="<?= $view->e($key) ?>"<?= $value['metric'] === $key ? ' selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?></select>
        <label for="plan-days" class="small">Days before and after</label><select id="plan-days" name="days"><?php foreach (MeasurementPlanService::PERIODS as $days): ?><option value="<?= $days ?>"<?= $value['days'] === $days ? ' selected' : '' ?>><?= $days ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="plan-targets">Target pages (one address per line; empty means the whole property)</label><textarea id="plan-targets" name="target_pages" rows="3"><?= $view->e(implode("\n", $value['target_pages'])) ?></textarea></div>
        <div class="field"><label for="plan-queries">Target queries (one per line, Search Console metrics only)</label><textarea id="plan-queries" name="target_queries" rows="2"><?= $view->e(implode("\n", $value['target_queries'])) ?></textarea></div>
        <div class="field"><label for="plan-controls">Control pages (similar pages this change did not touch, one per line)</label><textarea id="plan-controls" name="control_pages" rows="2"><?= $view->e(implode("\n", $value['control_pages'])) ?></textarea></div>
        <div class="field"><label for="plan-goal">After-only goal (optional): the primary metric to reach in the days after the change. Use it for a new page, which has no data before the change; at most this for average position.</label><input id="plan-goal" name="goal" inputmode="decimal" value="<?= $view->e($value['goal'] !== null ? rtrim(rtrim(number_format((float) $value['goal'], 2, '.', ''), '0'), '.') : '') ?>" placeholder="500"></div>
        <div class="field"><label for="plan-reviews">Review dates (YYYY-MM-DD, comma-separated, in <?= $view->e($measurement['timezone']) ?>)</label><input id="plan-reviews" name="review_dates" value="<?= $view->e(implode(', ', $value['review_dates'])) ?>"></div>
        <button class="button button-primary">Save plan</button>
    </form>
    </details>
    <?php endif; ?>
</section>
