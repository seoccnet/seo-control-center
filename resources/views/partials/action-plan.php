<?php

declare(strict_types=1);

/**
 * What to do about a finding (ADR-031): SEOcc's action plan for its kind of
 * work. The compact form shows the first change only. No script.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Opportunities\Playbook $plan
 * @var bool $compact
 * @var string|null $projectPublicId the project, for the link to its results
 * @var string|null $ownRecord this site's measured results for the plan (0.45.0), as one sentence
 */
$compact ??= false;
$ownRecord ??= null;
?>
<?php if ($compact): ?>
<p class="action-plan-compact"><strong>Next step:</strong> <?= $view->e($plan->firstChange()) ?></p>
<?php else: ?>
<section class="card action-plan" id="action-plan" aria-labelledby="action-plan-heading">
    <h3 id="action-plan-heading">Action plan: <?= $view->e($plan->title) ?></h3>
    <p><?= $view->e($plan->goal) ?></p>
    <p class="small action-plan-tested"><span class="<?= $plan->tested === 'tested' ? 'badge badge-ok' : ($plan->tested === 'guidance' ? 'badge badge-info' : 'badge') ?>"><?= $view->e($plan->testedLabel()) ?></span> <?= $view->e($plan->testedNote()) ?></p>
    <?php if ($ownRecord !== null): ?><p class="small action-plan-own"><strong><?= $view->e($ownRecord) ?></strong> <a href="/projects/<?= $view->e((string) ($projectPublicId ?? '')) ?>/annotations/results">What worked</a></p><?php endif; ?>
    <?php foreach ($plan->sections as $section): ?>
        <h4><?= $view->e($section['label']) ?></h4>
        <?php if ($section['label'] === 'What to change'): ?>
            <ol class="action-plan-steps"><?php foreach ($section['items'] as $item): ?><li><?= $view->e($item) ?></li><?php endforeach; ?></ol>
        <?php else: ?>
            <ul><?php foreach ($section['items'] as $item): ?><li><?= $view->e($item) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($plan->cautions !== []): ?>
        <h4>Avoid</h4>
        <ul><?php foreach ($plan->cautions as $caution): ?><li><?= $view->e($caution) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($plan->alsoConsider !== []): ?>
        <details class="help-details"><summary>Also consider (few published tests: try on a few pages first)</summary><ul><?php foreach ($plan->alsoConsider as $idea): ?><li><?= $view->e($idea) ?></li><?php endforeach; ?></ul></details>
    <?php endif; ?>
    <p class="muted small">Guidance for this kind of finding. It does not change the finding’s impact, estimate or priority.</p>
</section>
<?php endif; ?>
