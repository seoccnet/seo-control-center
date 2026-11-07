<?php
/**
 * The Quest Log (ADR-035). Chapter 1 (Foundations) keeps the clarity of the
 * former Get started guide; other chapters list learning quests.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Quests\QuestLog|null $log
 * @var SeoControlCenter\Domain\Quests\ChapterState $chapter
 * @var list<SeoControlCenter\Domain\Projects\Project> $projects
 * @var SeoControlCenter\Domain\Projects\Project|null $project
 * @var bool $isAdmin
 * @var SeoControlCenter\Modules\Quests\GuideCatalogue $guide
 */
use SeoControlCenter\Domain\Quests\QuestState;
use SeoControlCenter\Domain\Quests\SetupStatus;

$view->extend('layouts/app', ['title' => 'Quest Log']);
$ring = static fn(int $percent): string => '<svg class="quest-ring" viewBox="0 0 36 36" aria-hidden="true" focusable="false"><circle class="quest-ring-track" cx="18" cy="18" r="15.5" pathLength="100"></circle>' . ($percent > 0 ? '<circle class="quest-ring-value" cx="18" cy="18" r="15.5" pathLength="100" stroke-dasharray="' . min(100, $percent) . ' 100"></circle>' : '') . '</svg>';
?>
<div class="onboarding-page quest-log">
    <header class="page-header">
        <div><p class="portfolio-eyebrow">Learn SEOcc on your own website</p><h1>Quest Log</h1><p class="muted">Finish quests on your real project. A quest is done when SEOcc can see the work in your data. Skip anything that doesn’t fit, and come back any time.</p></div>
        <a class="button button-ghost" href="/">Explore my workspace</a>
    </header>
    <?php if ($log === null): ?>
        <section class="card"><h2>You need a workspace first</h2><p class="muted">Ask a workspace owner to invite you. Your Quest Log will be available once you have access.</p></section>
    <?php else: $foundations = $log->foundations(); ?>
        <?php foreach ($log->announce as $earned): ?>
            <p class="quest-toast" role="status" data-quest-toast><?= $view->icon('check') ?>Rank earned: <strong><?= $view->e($earned->rank) ?></strong> · <?= $view->e($earned->title) ?> complete.</p>
        <?php endforeach; ?>
        <?php if ($log->newQuests !== []): ?>
            <section class="card quest-new" aria-labelledby="new-quests-heading"><h2 id="new-quests-heading">New quests available</h2><p class="muted">Your installation can now do more. These quests were added; ranks you already earned are kept.</p><ul class="plain-list"><?php foreach ($log->newQuests as $new): ?><li><a href="/quests/<?= $view->e($new->key) ?>"><?= $view->e($new->title) ?></a></li><?php endforeach; ?></ul></section>
        <?php endif; ?>
        <?php if ($log->hidden): ?>
            <section class="card quest-hidden-notice"><p class="muted">The Quest Log card is hidden from your dashboard and project overview.</p><form method="post" action="/quests/visibility"><?= $view->csrfField() ?><input type="hidden" name="hidden" value="0"><input type="hidden" name="return" value="/quests"><button class="button button-ghost button-small" type="submit">Show it again</button></form></section>
        <?php endif; ?>
        <section class="card quest-campaign" aria-labelledby="campaign-heading">
            <div class="quest-campaign-heading"><div><h2 id="campaign-heading">Your campaign</h2><p class="muted"><?= $log->done() ?> of <?= $log->total() ?> quests resolved · <?= $log->percent() ?> %</p></div>
            <?php if (!$log->hidden): ?><form method="post" action="/quests/visibility"><?= $view->csrfField() ?><input type="hidden" name="hidden" value="1"><input type="hidden" name="return" value="/quests"><button class="button button-ghost button-small" type="submit">Hide from dashboard</button></form><?php endif; ?></div>
            <progress class="onboarding-meter" value="<?= $log->done() ?>" max="<?= max(1, $log->total()) ?>" aria-label="Campaign quests resolved"><?= $log->done() ?> / <?= $log->total() ?></progress>
            <nav class="quest-chapters" aria-label="Chapters">
                <?php foreach ($log->chapters as $item): $c = $item->chapter; ?>
                    <a class="quest-chapter<?= $item->earned !== null ? ' is-earned' : '' ?>" href="/quests?chapter=<?= $view->e($c->key) ?>"<?= $c->number === $chapter->chapter->number ? ' aria-current="page"' : '' ?>>
                        <?= $ring($item->percent()) ?>
                        <span class="quest-chapter-text"><span class="quest-chapter-number">Chapter <?= $c->number ?></span><strong><?= $view->e($c->title) ?></strong><span class="quest-rank"><?= $item->earned !== null ? $view->icon('check') . $view->e($c->rank) : $view->e($c->rank) ?><span class="visually-hidden"><?= $item->earned !== null ? ' rank earned' : ' rank not earned yet' ?>, <?= $item->done ?> of <?= $item->total ?> done</span></span></span>
                        <?php if ($item->needsAttention): ?><span class="quest-attention" title="Needs attention"><?= $view->icon('info') ?><span class="visually-hidden">Needs attention</span></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </section>

        <section class="quest-chapter-body" aria-labelledby="chapter-heading">
            <div class="quest-chapter-intro">
                <p class="portfolio-eyebrow">Chapter <?= $chapter->chapter->number ?> · Rank: <?= $view->e($chapter->chapter->rank) ?><?= $chapter->earned !== null ? ' · earned' : '' ?></p>
                <h2 id="chapter-heading"><?= $view->e($chapter->chapter->title) ?></h2>
                <p class="muted"><?= $view->e($chapter->chapter->summary) ?></p>
            </div>
            <?php if ($chapter->chapter->number === 1): $next = $chapter->next !== null ? $chapter->quest($chapter->next) : null; $searchConsole = $chapter->quest('foundations.connect-search-console'); ?>
                <section class="card onboarding-progress" aria-labelledby="progress-heading">
                    <div><h3 id="progress-heading"><?= $chapter->complete() ? 'Your tracking setup is ready' : 'Let’s get your website tracking' ?></h3><p class="muted"><?= $chapter->done ?> of <?= $chapter->total ?> core steps complete<?= $searchConsole !== null ? ' · Google Search Console is optional.' : '.' ?><?= $chapter->total < count(array_filter($chapter->quests, static fn(QuestState $q): bool => !$q->setup?->optional)) ? ' Steps handled by your administrator are shown with their status but don’t count toward your progress.' : '' ?></p></div>
                    <progress class="onboarding-meter" value="<?= $chapter->done ?>" max="<?= max(1, $chapter->total) ?>" aria-label="Core setup steps completed"><?= $chapter->done ?> / <?= $chapter->total ?></progress>
                    <?php if ($chapter->needsAttention): ?><p class="quest-attention-note"><?= $view->icon('info') ?>A setup step needs attention. Review the steps marked “Needs attention” below.<?= $chapter->earned !== null ? ' Your Navigator rank is kept.' : '' ?></p><?php endif; ?>
                    <?php if ($next !== null): ?>
                        <div class="onboarding-next"><p><strong>Next: <?= $view->e($next->quest->title) ?></strong></p><a class="button button-ghost button-small" href="#setup-step-<?= $view->e($next->quest->key) ?>">Review next step<?= $view->icon('arrow-right') ?></a></div>
                    <?php endif; ?>
                    <?php if ($projects !== []): ?>
                        <form method="get" action="/quests" class="onboarding-project-picker">
                            <input type="hidden" name="chapter" value="foundations">
                            <label for="setup-project">Website to set up</label>
                            <div class="actions"><select id="setup-project" name="project"><?php foreach ($projects as $choice): ?><option value="<?= $view->e($choice->publicId) ?>"<?= $project?->publicId === $choice->publicId ? ' selected' : '' ?>><?= $view->e($choice->name) ?></option><?php endforeach; ?></select><button class="button button-ghost" type="submit">Show setup</button></div>
                        </form>
                    <?php endif; ?>
                </section>
                <ol class="onboarding-steps" aria-label="Website setup steps">
                    <?php foreach ($chapter->quests as $index => $state): $step = $state->setup; if ($step === null) continue; ?>
                        <li id="setup-step-<?= $view->e($state->quest->key) ?>" class="card onboarding-step<?= $step->ready() ? ' is-complete' : '' ?>"<?= $chapter->next === $state->quest->key ? ' aria-current="step"' : '' ?>>
                            <span class="onboarding-number" aria-hidden="true"><?= $step->ready() ? $view->icon('check') : $index + 1 ?></span>
                            <div class="onboarding-step-body">
                                <div class="onboarding-step-heading"><h3><?= $view->e($step->title) ?></h3><span class="quest-step-chips"><span class="onboarding-status<?= $step->needsAttention() ? ' needs-attention' : '' ?>"><?= $view->e($step->status) ?></span><?php if ($state->delegated()): ?><span class="quest-chip">Handled by your administrator</span><?php endif; ?></span></div>
                                <p class="muted"><?= $view->e($step->description) ?></p>
                                <div class="quest-step-actions">
                                    <?php if ($step->href !== null): ?><a class="button button-ghost button-small" href="<?= $view->e($step->href) ?>"><?= $view->e($step->action) ?><?= $view->icon('arrow-right') ?></a><?php elseif ($step->hint !== null): ?><p class="small muted"><?= $view->e($step->hint) ?></p><?php endif; ?>
                                    <a class="quest-details-link small" href="/quests/<?= $view->e($state->quest->key) ?>">Tips and details<span class="visually-hidden"> for <?= $view->e($step->title) ?></span></a>
                                </div>
                                <?php if ($state->quest->key === 'foundations.background-tasks' && $isAdmin): ?><details class="setup-guide"><summary>How to set up the cron job</summary><p>In cPanel → Cron Jobs, choose “Once Per Minute” (<code>* * * * *</code>). Use the PHP executable supplied by your host and the full path to <code>bin/cron.php</code> in your installation. System health shows whether cron is running and which PHP version it uses.</p><p>Example: <code>php /path/to/seo-control-center/bin/cron.php</code>. Replace this example with your actual hosting paths.</p></details><?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <p class="small muted">Setup status is read live from your current settings, so a step can go back to “Needs attention” (for example if Google is disconnected or the budget is used up). Refresh this page after background work completes.</p>
            <?php elseif ($chapter->quests === []): ?>
                <section class="card"><p class="muted">There are no quests in this chapter for your role. Continue with the next chapter.</p></section>
            <?php else: ?>
                <progress class="onboarding-meter" value="<?= $chapter->done ?>" max="<?= max(1, $chapter->total) ?>" aria-label="Chapter <?= $chapter->chapter->number ?> quests resolved"><?= $chapter->done ?> / <?= $chapter->total ?></progress>
                <ol class="quest-list" aria-label="Quests in <?= $view->e($chapter->chapter->title) ?>">
                    <?php foreach ($chapter->quests as $state): $next = $state->nextStep(); ?>
                        <li class="card quest-card<?= $state->resolved() ? ' is-complete' : '' ?>"<?= $log->current?->quest->key === $state->quest->key ? ' aria-current="step"' : '' ?>>
                            <div class="onboarding-step-heading"><h3><a href="/quests/<?= $view->e($state->quest->key) ?>"><?= $view->e($state->quest->title) ?></a></h3><span class="onboarding-status"><?= $view->e($state->status()) ?></span></div>
                            <p class="muted"><?= $view->e($state->quest->story) ?></p>
                            <p class="small quest-meta">
                                <span>About <?= $state->quest->minutes ?> min</span>
                                <?php if ($state->quest->paid): ?><span class="quest-chip">Uses paid DataForSEO work</span><?php endif; ?>
                                <?php if ($state->quest->weekly): ?><span><?= $state->lastCheckIn !== null ? 'Last check-in: ' . $view->e(substr($state->lastCheckIn, 5)) : 'No check-in yet' ?></span><?php elseif (!$state->resolved() && $next !== null): ?><span>Next: <?= $view->e($next->step->label) ?><?= $next->waiting() ? ' (happens in the background)' : '' ?></span><?php endif; ?>
                            </p>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <p class="small muted">Quests stay complete once done. Waiting steps finish on their own after background work runs; SEOcc re-checks them every hour.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
