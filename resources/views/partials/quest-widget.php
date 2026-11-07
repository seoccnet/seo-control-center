<?php
/**
 * Compact Quest Log card (dashboard and project overview). Replaces the
 * former setup banner; hiding it is a server-side preference.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Quests\QuestLog $log
 * @var SeoControlCenter\Domain\Projects\Project|null $project
 * @var string $return
 */
$foundations = $log->foundations();
$current = $log->current;
$setupOpen = !$foundations->complete();
$projectQuery = $project !== null ? '&amp;project=' . $view->e($project->publicId) : '';
$next = $current?->nextStep();
?>
<section class="card onboarding-banner quest-widget" aria-labelledby="quest-widget-heading">
    <div>
        <p class="portfolio-eyebrow">Quest Log<?php if ($log->needsAttention()): ?> · <span class="quest-attention-inline"><?= $view->icon('info') ?>Needs attention</span><?php endif; ?></p>
        <?php if ($setupOpen): ?>
            <h2 id="quest-widget-heading">Let’s get your website tracking</h2>
            <p class="muted"><?= $foundations->done ?> of <?= $foundations->total ?> core steps complete<?= $project !== null ? ' for ' . $view->e($project->name) : '' ?>.<?= $current?->setup !== null ? ' Next: ' . $view->e($current->quest->title) . '.' : '' ?> Follow the Quest Log at your own pace; your progress stays with your settings.</p>
        <?php elseif ($current !== null): ?>
            <h2 id="quest-widget-heading"><?= $view->e($current->quest->title) ?></h2>
            <p class="muted"><?= $next !== null ? 'Next: ' . $view->e($next->step->label) . ($next->waiting() ? ' (happens in the background).' : '.') : $view->e($current->quest->story) ?></p>
        <?php else: ?>
            <h2 id="quest-widget-heading">Campaign complete</h2>
            <p class="muted">Every quest is done. Keep the habit with a weekly check-in.</p>
        <?php endif; ?>
        <?php if ($log->newQuests !== []): ?><p class="small"><a href="/quests"><?= count($log->newQuests) === 1 ? 'New quest available' : count($log->newQuests) . ' new quests available' ?>: <?= $view->e(implode(', ', array_map(static fn($q): string => $q->title, array_slice($log->newQuests, 0, 3)))) ?><?= count($log->newQuests) > 3 ? ' …' : '' ?></a></p><?php endif; ?>
        <progress class="onboarding-meter" value="<?= $log->done() ?>" max="<?= max(1, $log->total()) ?>" aria-label="Quest Log campaign progress"><?= $log->percent() ?> %</progress>
    </div>
    <div class="onboarding-banner-actions">
        <?php if ($setupOpen || ($log->needsAttention() && $current === null)): ?>
            <a class="button" href="/quests?chapter=foundations<?= $projectQuery ?>"><?= $foundations->done > 1 ? 'Continue setup' : 'Set up my website' ?><?= $view->icon('arrow-right') ?></a>
        <?php elseif ($current !== null): ?>
            <a class="button" href="/quests/<?= $view->e($current->quest->key) ?>">Continue quest<?= $view->icon('arrow-right') ?></a>
        <?php else: ?>
            <a class="button" href="/quests">Open the Quest Log<?= $view->icon('arrow-right') ?></a>
        <?php endif; ?>
        <?php if ($log->needsAttention() && !$setupOpen): ?><a class="button button-ghost button-small" href="/quests?chapter=foundations<?= $projectQuery ?>">Review setup</a><?php endif; ?>
        <form method="post" action="/quests/visibility"><?= $view->csrfField() ?><input type="hidden" name="hidden" value="1"><input type="hidden" name="return" value="<?= $view->e($return) ?>"><button class="button button-ghost button-small" type="submit">Hide this card</button></form>
    </div>
    <?php foreach ($log->announce as $earned): ?>
        <p class="quest-toast" role="status" data-quest-toast><?= $view->icon('check') ?>Rank earned: <strong><?= $view->e($earned->rank) ?></strong> · <?= $view->e($earned->title) ?> complete.</p>
    <?php endforeach; ?>
</section>
