<?php
declare(strict_types=1);

use SeoControlCenter\Domain\Measurement\ResultsLedger;
use SeoControlCenter\Modules\Annotations\AnnotationInput;
use SeoControlCenter\Modules\Annotations\ResultsLedgerService;

/**
 * What worked on your site (0.45.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var array{rows: list<array{annotation: SeoControlCenter\Modules\Annotations\Annotation, metric: string, status: string, label: string, effect: ?float, unit: string, attribution: ?string, playbook: ?string, playbook_title: ?string}>, by_kind: list<array{group: string, label: string, changes: int, measured: int, improved: int, declined: int, unchanged: int, insufficient: int, open: int, controlled: int, median_percent: ?float, median_positions: ?float}>, by_plan: list<array{group: string, label: string, changes: int, measured: int, improved: int, declined: int, unchanged: int, insufficient: int, open: int, controlled: int, median_percent: ?float, median_positions: ?float}>, unplanned: list<SeoControlCenter\Modules\Annotations\Annotation>, unfrozen: list<SeoControlCenter\Modules\Annotations\Annotation>, limited: bool} $ledger
 */
$base = '/projects/' . $project->publicId . '/annotations';
$canEdit ??= false;
$view->extend('layouts/app', ['title' => 'What worked · ' . $project->name]);
$median = static fn(array $g): string => $g['median_percent'] !== null ? sprintf('%+.0f %%', $g['median_percent']) : ($g['median_positions'] !== null ? sprintf('%+.1f positions', $g['median_positions']) : '—');
$badge = static fn(string $status): string => match (ResultsLedger::outcome($status)) {
    'improved' => 'badge-ok',
    'declined' => 'badge-error',
    'unchanged', 'insufficient' => 'badge-warning',
    default => 'badge-info',
};
$table = static function (array $groups, string $heading) use ($view, $median): string {
    $html = '<div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">' . $view->e($heading) . '</th><th scope="col" class="num">Measured</th><th scope="col" class="num">Improved</th><th scope="col" class="num">Declined</th><th scope="col" class="num">No clear change</th><th scope="col" class="num">Median effect</th><th scope="col" class="num">Not readable</th><th scope="col" class="num">Still open</th></tr></thead><tbody>';
    foreach ($groups as $g) {
        $html .= '<tr><td>' . $view->e($g['label']) . ($g['measured'] > 0 && $g['measured'] < ResultsLedger::FEW ? ' <span class="muted small">too few to show a pattern</span>' : '') . '</td>'
            . '<td class="num">' . $view->e((string) $g['measured']) . ($g['controlled'] > 0 ? ' <span class="muted small">(' . $view->e((string) $g['controlled']) . ' controlled)</span>' : '') . '</td>'
            . '<td class="num">' . $view->e((string) $g['improved']) . '</td><td class="num">' . $view->e((string) $g['declined']) . '</td><td class="num">' . $view->e((string) $g['unchanged']) . '</td>'
            . '<td class="num">' . $view->e($median($g)) . '</td><td class="num">' . $view->e((string) $g['insufficient']) . '</td><td class="num">' . $view->e((string) $g['open']) . '</td></tr>';
    }

    return $html . '</tbody></table></div>';
};
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'annotations']) ?>
<div class="manage-workspace">
<header class="workspace-intro">
    <div><p class="portfolio-eyebrow">Manage · Change timeline</p><h2>What worked on your site</h2><p class="muted">The measured results of your SEO changes with a measurement plan, by kind of change and by the action plan they came from.</p></div>
    <a class="button button-ghost" href="<?= $view->e($base) ?>">Change timeline</a>
</header>

<?php if ($ledger['unmeasured'] > 0 && $canEdit): ?>
    <section class="card" id="measure-past" aria-labelledby="measure-past-title">
        <h2 id="measure-past-title">Measure your past changes</h2>
        <p><?= $view->e(number_format($ledger['unmeasured'])) ?> page <?= $ledger['unmeasured'] === 1 ? 'change' : 'changes' ?> from the last year <?= $ledger['unmeasured'] === 1 ? 'has' : 'have' ?> no measurement plan. SEOcc can give each the default plan now, from the search data it already stored: clicks for the page (CTR for a title or snippet change), 28 days before and after. Changes old enough show a result right away.</p>
        <form method="post" action="<?= $view->e($base . '/results/measure-past') ?>"><?= $view->csrfField() ?><button type="submit" class="button button-primary">Measure past changes</button></form>
        <p class="muted small">Up to <?= $view->e((string) ResultsLedgerService::PAST_BATCH) ?> changes per click. These results are marked “measured afterwards”: the plan was set after the change, so treat them as a rough guide. Free; nothing is fetched or paid for.</p>
    </section>
<?php endif; ?>

<?php if ($ledger['rows'] === []): ?>
    <section class="card">
        <h2>No measured changes yet</h2>
        <p>Open a change on the timeline and set up its measurement plan: what it should move, which pages, and how long. A change deployed from a change bundle that started on a finding gets a draft plan by itself.</p>
    </section>
<?php else: ?>
    <section class="card" aria-labelledby="by-kind">
        <h2 id="by-kind">By kind of change</h2>
        <?= $table($ledger['by_kind'], 'Kind of change') ?>
        <p class="muted small">Measured means read behind every check: the change made, a complete frozen baseline, and the full period after the change imported. Effects are “better is positive”: percent for clicks, impressions, CTR, sessions and key events; positions gained for average position. Controlled results compare with control pages; the rest are associations, which seasonality, competitors and other changes can explain.</p>
    </section>

    <?php if ($ledger['by_plan'] !== []): ?>
        <section class="card" aria-labelledby="by-plan">
            <h2 id="by-plan">By action plan</h2>
            <p class="muted small">Changes deployed from a change bundle started on a finding. Each finding with this plan shows your own record next to its “how well tested” label.</p>
            <?= $table($ledger['by_plan'], 'Action plan') ?>
        </section>
    <?php endif; ?>

    <section class="card" aria-labelledby="all-results">
        <h2 id="all-results">Every measured change</h2>
        <p class="muted small">“Overlaps” means other changes to the same page, or site-wide, fall inside the comparison period: the effect cannot be credited to this change alone, and the same effect can appear on each of them.</p>
        <div class="table-scroll">
            <table class="table table-dense">
                <thead><tr><th scope="col">Change</th><th scope="col">Kind</th><th scope="col">Metric</th><th scope="col">Result</th><th scope="col" class="num">Effect</th></tr></thead>
                <tbody>
                    <?php foreach ($ledger['rows'] as $row): ?>
                        <tr>
                            <td><a href="<?= $view->e($base . '/' . $row['annotation']->publicId . '/impact') ?>"><?= $view->e($row['annotation']->title) ?></a><div class="muted small"><?= $view->e($row['annotation']->date) ?><?= $row['playbook_title'] !== null ? ' · ' . $view->e($row['playbook_title']) : '' ?></div></td>
                            <td class="small"><?= $view->e(AnnotationInput::KINDS[$row['annotation']->kind] ?? $row['annotation']->kind) ?></td>
                            <td class="small"><?= $view->e(ResultsLedgerService::metricLabel($row['metric'])) ?></td>
                            <td><span class="badge <?= $badge($row['status']) ?>"><?= $view->e($row['label']) ?></span><?php if ($row['attribution'] === 'controlled'): ?> <span class="muted small">controlled</span><?php endif; ?><?php if ($row['afterwards']): ?> <span class="muted small">measured afterwards</span><?php endif; ?><?php if ($row['overlapping'] > 0): ?> <span class="muted small">overlaps <?= $view->e((string) $row['overlapping']) ?> other <?= $row['overlapping'] === 1 ? 'change' : 'changes' ?></span><?php endif; ?></td>
                            <td class="num"><?= $row['effect'] !== null ? $view->e($row['unit'] === 'positions' ? sprintf('%+.1f positions', $row['effect']) : sprintf('%+.0f %%', $row['effect'])) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($ledger['limited']): ?><p class="muted small">Showing the newest <?= $view->e((string) ResultsLedgerService::LIMIT) ?> changes with a plan.</p><?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($ledger['unfrozen'] !== [] || $ledger['unplanned'] !== []): ?>
    <section class="card" aria-labelledby="reminders">
        <h2 id="reminders">To make results readable</h2>
        <?php if ($ledger['unfrozen'] !== []): ?>
            <h3>Freeze the baseline</h3>
            <p class="muted small">These plans have no frozen baseline, so their result can never be read. Freeze it while the period before the change is still imported.</p>
            <ul><?php foreach ($ledger['unfrozen'] as $a): ?><li><a href="<?= $view->e($base . '/' . $a->publicId . '/impact') ?>"><?= $view->e($a->title) ?></a> <span class="muted small"><?= $view->e($a->date) ?></span></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if ($ledger['unplanned'] !== []): ?>
            <h3>Changes on a page without a plan</h3>
            <p class="muted small">Recorded in the last <?= $view->e((string) ResultsLedgerService::REMINDER_DAYS) ?> days. Without a plan, they only get the early read on the timeline.</p>
            <ul><?php foreach (array_slice($ledger['unplanned'], 0, 20) as $a): ?><li><a href="<?= $view->e($base . '/' . $a->publicId . '/impact') ?>"><?= $view->e($a->title) ?></a> <span class="muted small"><?= $view->e($a->date) ?> · <?= $view->e(AnnotationInput::KINDS[$a->kind] ?? $a->kind) ?></span></li><?php endforeach; ?></ul>
            <?php if (count($ledger['unplanned']) > 20): ?><p class="muted small">And <?= $view->e((string) (count($ledger['unplanned']) - 20)) ?> more.</p><?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
</div>
