<?php
/**
 * One quest: story, "You will learn", steps with "Show me", tips, mistakes and the guide.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Quests\QuestLog $log
 * @var SeoControlCenter\Domain\Quests\QuestState $state
 * @var SeoControlCenter\Domain\Projects\Project|null $project
 * @var SeoControlCenter\Modules\Quests\ShowMeTargets $targets
 * @var SeoControlCenter\Modules\Quests\GuideCatalogue $guide
 * @var bool $assistant
 * @var bool $isAdmin
 */
use SeoControlCenter\Domain\Quests\QuestCatalogue;

$quest = $state->quest;
$chapter = QuestCatalogue::chapter($quest->chapter);
$setup = $state->setup;
$next = $state->nextStep();
$view->extend('layouts/app', ['title' => $quest->title . ' · Quest Log', 'crumbs' => [['/quests', 'Quest Log'], ['/quests?chapter=' . ($chapter->key ?? ''), $chapter->title ?? 'Chapter'], [null, $quest->title]]]);
$guideTitle = $guide->title($quest->guide);
?>
<div class="onboarding-page quest-detail">
    <header class="page-header">
        <div>
            <p class="portfolio-eyebrow">Chapter <?= $quest->chapter ?> · <?= $view->e($chapter->title ?? '') ?></p>
            <h1><?= $view->e($quest->title) ?></h1>
            <p class="quest-story"><?= $view->e($quest->story) ?></p>
            <p class="small quest-meta">
                <span class="onboarding-status<?= $setup?->needsAttention() === true ? ' needs-attention' : '' ?>"><?= $view->e($state->status()) ?></span>
                <?php if ($state->delegated()): ?><span class="quest-chip">Handled by your administrator</span><?php endif; ?>
                <span>About <?= $quest->minutes ?> min</span>
                <?php if ($quest->paid): ?><span class="quest-chip">Uses paid DataForSEO work</span><?php endif; ?>
            </p>
        </div>
        <a class="button button-ghost" href="/quests?chapter=<?= $view->e($chapter->key ?? '') ?>">Back to the chapter</a>
    </header>

    <?php foreach ($log->announce as $earned): ?>
        <p class="quest-toast" role="status" data-quest-toast><?= $view->icon('check') ?>Rank earned: <strong><?= $view->e($earned->rank) ?></strong> · <?= $view->e($earned->title) ?> complete.</p>
    <?php endforeach; ?>
    <?php if ($log->newQuests !== []): ?>
        <p class="small"><a href="/quests"><?= count($log->newQuests) === 1 ? 'New quest available' : count($log->newQuests) . ' new quests available' ?> in the Quest Log.</a></p>
    <?php endif; ?>
    <?php if ($quest->paid): ?>
        <p class="notice notice-warning">This quest involves paid DataForSEO work from your own account. Review your provider budget before you start it. SEOcc never starts paid work just because you opened a quest.</p>
    <?php endif; ?>

    <?php if ($setup !== null): ?>
        <section class="card quest-live" aria-labelledby="live-heading">
            <h2 id="live-heading">Current status<?= $project !== null ? ' for ' . $view->e($project->name) : '' ?></h2>
            <p class="muted"><?= $view->e($setup->description) ?></p>
            <?php if ($setup->href !== null): ?><a class="button button-small" href="<?= $view->e($setup->href) ?>"><?= $view->e($setup->action) ?><?= $view->icon('arrow-right') ?></a><?php elseif ($setup->hint !== null): ?><p class="small muted"><?= $view->e($setup->hint) ?></p><?php endif; ?>
            <?php if ($quest->key === 'foundations.background-tasks' && $isAdmin): ?><details class="setup-guide"><summary>How to set up the cron job</summary><p>In cPanel → Cron Jobs, choose “Once Per Minute” (<code>* * * * *</code>). Use the PHP executable supplied by your host and the full path to <code>bin/cron.php</code> in your installation. System health shows whether cron is running and which PHP version it uses.</p><p>Example: <code>php /path/to/seo-control-center/bin/cron.php</code>. Replace this example with your actual hosting paths.</p></details><?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="card" aria-labelledby="learn-heading">
        <h2 id="learn-heading">You will learn</h2>
        <ul class="quest-learn"><?php foreach ($quest->learn as $skill): ?><li><?= $view->e($skill) ?></li><?php endforeach; ?></ul>
    </section>

    <section class="card" aria-labelledby="steps-heading">
        <h2 id="steps-heading">Steps</h2>
        <ol class="quest-steps">
            <?php foreach ($state->steps as $index => $step): $url = $step->step->showMe !== null ? $targets->url($step->step->showMe, $project) : null; ?>
                <li class="quest-step<?= $step->done ? ' is-complete' : '' ?>"<?= $next === $step ? ' aria-current="step"' : '' ?>>
                    <span class="onboarding-number" aria-hidden="true"><?= $step->done ? $view->icon('check') : ($step->waiting() ? '⏳' : $index + 1) ?></span>
                    <div class="quest-step-body">
                        <p><strong><?= $view->e($step->step->label) ?></strong> <span class="onboarding-status"><?= $step->done ? 'Done' : ($step->waiting() ? 'Waiting' : 'To do') ?></span></p>
                        <?php if ($step->waiting()): ?><p class="small muted">This happens in the background once imports or checks have run. You don’t need to stay here: SEOcc re-checks every hour and marks it done.</p><?php endif; ?>
                        <?php if ($url !== null && !$step->done): ?><a class="button button-ghost button-small" href="<?= $view->e($url) ?>"<?= $step->step->showMe?->selector !== null ? ' data-show-me="' . $view->e($step->step->showMe->selector) . '" data-show-me-label="' . $view->e($step->step->label) . '"' : '' ?>><?= $view->e($step->step->showMe?->label ?? 'Show me') ?><span class="visually-hidden">: <?= $view->e($step->step->label) ?></span></a><?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php if ($assistant && $next !== null): ?>
            <button type="button" class="button button-secondary button-small" hidden data-assistant-ask="<?= $view->e('I am working on the SEOcc Quest Log quest "' . $quest->title . '". The current step is "' . $next->step->label . '"' . ($project !== null ? ' for project_id ' . $project->publicId : '') . '. Explain briefly how to complete this step in SEOcc and what to look for in my saved data. Do not change anything.') ?>">Ask AI about this quest</button>
            <p class="small muted">Ask AI uses one request from your AI assistant budget.</p>
        <?php endif; ?>
    </section>

    <div class="quest-advice">
        <section class="card" aria-labelledby="tips-heading">
            <h2 id="tips-heading"><span aria-hidden="true">💡</span> Pro tips</h2>
            <ul><?php foreach ($quest->tips as $tip): ?><li><?= $view->e($tip) ?></li><?php endforeach; ?></ul>
        </section>
        <section class="card" aria-labelledby="mistakes-heading">
            <h2 id="mistakes-heading"><span aria-hidden="true">⚠️</span> Common mistakes</h2>
            <ul><?php foreach ($quest->mistakes as $mistake): ?><li><?= $view->e($mistake) ?></li><?php endforeach; ?></ul>
        </section>
    </div>

    <section class="card quest-footer" aria-label="More">
        <?php if ($guideTitle !== null): ?><a class="button button-ghost button-small" href="/quests/guide/<?= $view->e($quest->guide) ?>">Read the guide: <?= $view->e($guideTitle) ?></a><?php endif; ?>
        <?php if ($setup === null && !$quest->weekly): ?>
            <?php if ($state->skipped()): ?>
                <form method="post" action="/quests/<?= $view->e($quest->key) ?>/unskip"><?= $view->csrfField() ?><button class="button button-ghost button-small" type="submit">Bring this quest back</button></form>
            <?php elseif (!$state->done()): ?>
                <form method="post" action="/quests/<?= $view->e($quest->key) ?>/skip"><?= $view->csrfField() ?><button class="button button-ghost button-small" type="submit">Skip this quest</button></form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
