<?php
declare(strict_types=1);

use SeoControlCenter\Modules\Annotations\AnnotationInput;
use SeoControlCenter\Modules\Annotations\AnnotationService;
use SeoControlCenter\Modules\Annotations\ImpactWindow;

/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var array{items: list<SeoControlCenter\Modules\Annotations\Annotation>, total: int, page: int, pages: int} $listing
 * @var array<string, array{window: ImpactWindow, early: ?array<string, mixed>}> $insights
 * @var array{total: int, last30: int, phases: array<string, int>, kinds: array<string, int>, truncated: bool} $overview
 */
$base = '/projects/' . $project->publicId . '/annotations';
$view->extend('layouts/app', ['title' => 'Change timeline · ' . $project->name]);
$value = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
$signed = static fn(float $n, int $decimals, string $suffix): string => ($n > 0 ? '+' : ($n < 0 ? '−' : '±')) . number_format(abs($n), $decimals) . $suffix;
// Direction for colour only: up is good except for position, where lower is better. Changes below
// the threshold (1 % for clicks and impressions, 0.05 pp CTR, 0.1 positions) stay neutral.
$trend = static fn(?float $n, float $threshold, bool $lowerIsBetter = false): string => $n === null || abs($n) < $threshold ? 'flat' : (($n > 0) !== $lowerIsBetter ? 'up' : 'down');
$filtered = $from !== '' || $to !== '' || $urlFilter !== '';
$month = null;
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'annotations']) ?>
<div class="manage-workspace manage-timeline">
<header class="workspace-intro timeline-intro">
    <div><p class="portfolio-eyebrow">Manage · Change timeline</p><h2>Connect your changes with the evidence</h2><p class="muted">Record SEO work, follow each change through the weeks search engines need to react, and compare measured results.</p></div>
    <div class="actions"><a class="button button-ghost" href="<?= $view->e($base) ?>/results">What worked</a><?php if ($canEdit): ?><a class="button button-primary" href="#annotation-form"><?= $view->icon('plus') ?>Record a change</a><?php endif; ?></div>
</header>

<section class="timeline-stats" aria-label="Change timeline overview">
    <div class="timeline-stat"><span class="timeline-stat-label">Recorded changes</span><strong><?= number_format($overview['total']) ?></strong><span class="muted small"><?= number_format($overview['last30']) ?> in the last 30 days</span></div>
    <div class="timeline-stat impact-phase-waiting"><span class="timeline-stat-label"><span class="impact-dot" aria-hidden="true"></span>Waiting for recrawl</span><strong><?= $overview['phases'][ImpactWindow::WAITING] ?></strong><span class="muted small">Too early for search data</span></div>
    <div class="timeline-stat impact-phase-signal"><span class="timeline-stat-label"><span class="impact-dot" aria-hidden="true"></span>In the effect window</span><strong><?= $overview['phases'][ImpactWindow::SIGNAL] ?></strong><span class="muted small">Results are forming</span></div>
    <div class="timeline-stat impact-phase-ready"><span class="timeline-stat-label"><span class="impact-dot" aria-hidden="true"></span>Ready to review</span><strong><?= $overview['phases'][ImpactWindow::READY] ?></strong><span class="muted small"><?= $overview['phases'][ImpactWindow::SETTLED] ?> older changes settled</span></div>
</section>
<?php if ($overview['kinds'] !== []): ?>
<p class="timeline-kinds small"><span class="muted">Last <?= ImpactWindow::SETTLED_DAYS ?> days<?= $overview['truncated'] ? ' (latest 100)' : '' ?>:</span> <?php foreach ($overview['kinds'] as $kind => $count): ?><span class="annotation-kind annotation-kind-<?= $view->e($kind) ?>"><?= $view->e(AnnotationInput::KINDS[$kind] ?? $kind) ?> <strong><?= $count ?></strong></span><?php endforeach; ?></p>
<?php endif; ?>

<div class="timeline-layout">
<section class="card timeline-main" aria-labelledby="timeline-list-title">
    <div class="timeline-main-head"><h2 id="timeline-list-title">Recorded changes</h2><span class="muted small"><?= $listing['total'] ?> <?= $filtered ? 'matching' : 'recorded' ?> · page <?= $listing['page'] ?> of <?= $listing['pages'] ?></span></div>
    <details class="timeline-filter"<?= $filtered || isset($errors['filter']) ? ' open' : '' ?>>
        <summary>Filter by date or page<?= $filtered ? ' · filtered' : '' ?></summary>
        <form method="get" action="<?= $view->e($base) ?>" class="timeline-filter-form">
            <div class="field"><label for="annotation-from">From</label><input type="date" id="annotation-from" name="from" value="<?= $view->e($from) ?>"></div>
            <div class="field"><label for="annotation-to">To</label><input type="date" id="annotation-to" name="to" value="<?= $view->e($to) ?>"></div>
            <div class="field timeline-filter-url"><label for="annotation-filter-url">Exact page URL plus site-wide changes</label><input type="url" id="annotation-filter-url" name="url" value="<?= $view->e($urlFilter) ?>" maxlength="2048"></div>
            <?php if (isset($errors['filter'])): ?><p role="alert"><?= $view->e($errors['filter']) ?></p><?php endif; ?>
            <div class="actions"><button class="button button-secondary">Filter changes</button><a href="<?= $view->e($base) ?>">Clear filters</a></div>
        </form>
    </details>
    <?php if ($listing['items'] === []): ?><p class="timeline-empty">No recorded changes for this selection.<?= $canEdit && !$filtered ? ' Record the next title, content or technical change on the day you publish it.' : '' ?></p><?php endif; ?>
    <?php foreach ($listing['items'] as $item): $insight = $insights[$item->publicId] ?? null; $window = $insight['window'] ?? new ImpactWindow($item->kind, $item->date, $today); $early = $insight['early'] ?? null; ?>
        <?php if (substr($item->date, 0, 7) !== $month): $month = substr($item->date, 0, 7); ?>
            <?php if ($month !== null && $item !== $listing['items'][0]): ?></ol><?php endif; ?>
            <h3 class="timeline-month"><?= $view->e((new DateTimeImmutable($item->date))->format('F Y')) ?></h3>
            <ol class="annotation-timeline">
        <?php endif; ?>
        <li class="annotation-entry annotation-kind-<?= $view->e($item->kind) ?> impact-phase-<?= $view->e($window->phase) ?>">
            <div class="annotation-date"><time datetime="<?= $view->e($item->date) ?>"><span class="annotation-day"><?= $view->e((new DateTimeImmutable($item->date))->format('j')) ?></span><span class="annotation-month"><?= $view->e((new DateTimeImmutable($item->date))->format('M')) ?></span></time><span class="annotation-ago"><?= $window->elapsed === 0 ? 'Today' : ($window->elapsed === 1 ? '1 day ago' : $window->elapsed . ' days ago') ?></span></div>
            <div class="annotation-body">
                <div class="annotation-meta"><span class="annotation-kind annotation-kind-<?= $view->e($item->kind) ?>"><?= $view->e(AnnotationInput::KINDS[$item->kind] ?? $item->kind) ?></span><span class="url-cell"><?= $view->e($item->url ?? 'Site-wide change') ?></span><?php if ($item->via !== null): ?><span class="muted small">Last changed via <?= $view->e($item->via) ?></span><?php endif; ?></div>
                <h4><?= $view->e($item->title) ?></h4>
                <?php if ($item->note !== ''): ?><p class="annotation-note"><?= $view->e($item->note) ?></p><?php endif; ?>
                <?= $view->partial('partials/impact-track', ['window' => $window, 'startLabel' => 'Changed']) ?>
                <?php if ($early !== null): ?>
                    <?php if ($early['complete']): ?>
                        <div class="impact-early" role="group" aria-label="Early read from Google Search Console">
                            <span class="impact-early-label">Early read · <?= $early['days'] ?> days before vs after<?= $item->url === null ? ', whole site' : ', this page' ?></span>
                            <span class="impact-chip trend-<?= $trend($early['clicks'], 1.0) ?>">Clicks <?= $early['clicks'] === null ? 'n/a' : $view->e($signed($early['clicks'], 1, ' %')) ?></span>
                            <span class="impact-chip trend-<?= $trend($early['impressions'], 1.0) ?>">Impressions <?= $early['impressions'] === null ? 'n/a' : $view->e($signed($early['impressions'], 1, ' %')) ?></span>
                            <span class="impact-chip trend-<?= $trend($early['ctr'], 0.05) ?>">CTR <?= $early['ctr'] === null ? 'n/a' : $view->e($signed($early['ctr'], 2, ' pp')) ?></span>
                            <span class="impact-chip trend-<?= $trend($early['position'], 0.1, true) ?>">Position <?= $early['position'] === null ? 'n/a' : $view->e($signed($early['position'], 1, '')) ?></span>
                        </div>
                    <?php else: ?>
                        <p class="impact-early impact-early-missing small">Early read unavailable: Google data for <?= $early['days'] ?> days on one side of the change is incomplete<?= $item->url !== null ? ' or this URL has no measured rows' : '' ?>.</p>
                    <?php endif; ?>
                <?php elseif ($window->phase !== ImpactWindow::SETTLED): ?>
                    <p class="impact-early impact-early-missing small">Early read after 7 days of imported Google data following the change.</p>
                <?php endif; ?>
                <div class="annotation-actions">
                    <a class="button button-secondary button-small" href="<?= $view->e($base . '/' . $item->publicId . '/impact') ?>">Review impact</a>
                    <?php if ($canEdit): ?><a class="button button-ghost button-small" href="<?= $view->e($base . '/' . $item->publicId . '/edit') ?>">Edit</a><details class="annotation-remove"><summary>Remove</summary><form method="post" action="<?= $view->e($base . '/' . $item->publicId . '/delete') ?>"><?= $view->csrfField() ?><p>Remove this note from the timeline and AI context?</p><button class="button button-danger button-small">Remove annotation</button></form></details><?php endif; ?>
                </div>
            </div>
        </li>
    <?php endforeach; ?>
    <?php if ($listing['items'] !== []): ?></ol><?php endif; ?>
    <p class="muted small timeline-footnote">Early reads compare equal complete Google periods on both sides of the change day (up to 28 days each, change day excluded). They are associations, not proof the change caused the result.</p>
    <nav class="pagination" aria-label="Annotation pages"><?php foreach (['Previous' => $listing['page'] - 1, 'Next' => $listing['page'] + 1] as $label => $page): ?><?php if ($page >= 1 && $page <= $listing['pages']): ?><a class="button button-ghost" href="<?= $view->e($base . '?' . http_build_query(['page' => $page, 'from' => $from, 'to' => $to, 'url' => $urlFilter])) ?>"><?= $view->e($label) ?></a><?php endif; ?><?php endforeach; ?></nav>
</section>

<aside class="timeline-aside">
<?php if ($canEdit): ?>
<section class="card" id="annotation-form"><h2><?= $editingId === null ? 'Record a change' : 'Edit annotation' ?></h2>
<form method="post" action="<?= $view->e($base . ($editingId === null ? '' : '/' . $editingId)) ?>" class="stack">
    <?= $view->csrfField() ?>
    <div class="annotation-form-grid">
        <div class="field"><label for="annotation-date">Change date (UTC)</label><input id="annotation-date" name="date" type="date" required value="<?= $view->e($value('date')) ?>"<?= $view->invalid($errors, 'date', 'annotation-date') ?>><?= $view->fieldError($errors, 'date', 'annotation-date') ?></div>
        <div class="field"><label for="annotation-kind">Change type</label><select id="annotation-kind" name="kind"<?= $view->invalid($errors, 'kind', 'annotation-kind') ?>><?php foreach (AnnotationInput::KINDS as $kind => $label): ?><option value="<?= $view->e($kind) ?>"<?= $value('kind') === $kind ? ' selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?></select><?= $view->fieldError($errors, 'kind', 'annotation-kind') ?></div>
    </div>
    <div class="field"><label for="annotation-title">What changed?</label><input id="annotation-title" name="title" maxlength="120" required value="<?= $view->e($value('title')) ?>"<?= $view->invalid($errors, 'title', 'annotation-title') ?>><?= $view->fieldError($errors, 'title', 'annotation-title') ?></div>
    <div class="field"><label for="annotation-url">Exact page URL (optional)</label><input id="annotation-url" name="url" type="url" maxlength="2048" value="<?= $view->e($value('url')) ?>"<?= $view->invalid($errors, 'url', 'annotation-url') ?>><?= $view->fieldError($errors, 'url', 'annotation-url') ?></div>
    <div class="field"><label for="annotation-note">Details (optional)</label><textarea id="annotation-note" name="note" rows="3" maxlength="2000"<?= $view->invalid($errors, 'note', 'annotation-note') ?>><?= $view->e($value('note')) ?></textarea><?= $view->fieldError($errors, 'note', 'annotation-note') ?></div>
    <?php if ($editingId === null): ?><div class="field"><label><input type="checkbox" name="measure" value="1"<?= ($errors === [] || ($input['measure'] ?? null) === '1') ? ' checked' : '' ?>> Measure this change: draft a measurement plan for the page and freeze its baseline now</label><p class="muted small">Only for a change with a page URL. Change the metric, add target searches or control pages later on the change’s Impact page; changing them clears the baseline, so freeze it again there.</p></div><?php endif; ?>
    <p class="muted small">Dates use UTC. Page URLs match exactly; leave the URL blank for a site-wide change.</p>
    <div class="actions"><button class="button button-primary" type="submit">Save annotation</button><?php if ($editingId !== null): ?><a class="button button-ghost" href="<?= $view->e($base) ?>">Cancel editing</a><?php endif; ?></div>
</form></section>
<?php endif; ?>
<section class="card timeline-guide">
    <h2>When to expect results</h2>
    <p class="muted small">Typical days after a change before it shows in search data. Planning guidance, not a prediction for this site.</p>
    <table class="table timeline-window-table">
        <caption class="visually-hidden">Typical signal window per change type</caption>
        <thead><tr><th scope="col">Change type and what to watch</th><th scope="col" class="num">Signal</th></tr></thead>
        <tbody>
        <?php foreach (AnnotationInput::KINDS as $kind => $label): [$fromDay, $toDay, $watch] = ImpactWindow::KINDS[$kind]; ?>
            <tr><th scope="row"><span class="annotation-kind annotation-kind-<?= $view->e($kind) ?>"><?= $view->e($label) ?></span><span class="timeline-window-watch"><?= $view->e($watch) ?></span></th><td class="num">day <?= $fromDay ?>–<?= $toDay ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <details><summary>How to use the timeline</summary><ol><li>After editing your website, record the actual date, change type and a short title.</li><li>Use the exact page URL shown in Pages, or leave it blank for a whole-site release.</li><li>Follow the countdown: the early read appears after 7 days of data, the full 28-day comparison after 29 days.</li><li>Review chart markers in Overview, Performance and keyword history, or ask AI to compare the recorded changes with stored results. Check other influences before drawing conclusions.</li></ol><p>Do not put passwords, API keys or private customer information in notes. Authorized AI clients can read them as project context.</p></details>
    <p class="muted small"><?= $view->e(AnnotationService::CONTEXT) ?></p>
</section>
</aside>
</div>

</div>
