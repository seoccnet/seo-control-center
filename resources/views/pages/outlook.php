<?php

declare(strict_types=1);

/**
 * What the page could gain: click potential, the pages above it and its measured changes (0.47.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var string $base
 * @var array<string, mixed> $details
 */
use SeoControlCenter\Modules\Annotations\ResultsLedgerService;
use SeoControlCenter\Modules\Pages\PageOutlook;

$potential = $details['click_potential'];
$gap = $details['competitor_gap'];
$changes = $details['change_results'];
$statusBadge = static fn(string $status): string => match ($status) {
    'improved', 'reached' => 'badge-ok',
    'declined', 'not_reached' => 'badge-error',
    'no_plan' => 'badge-warning',
    default => 'badge-info',
};
?>
<section class="card" id="page-potential">
    <h2>Worth reaching the top <?= PageOutlook::TARGET ?></h2>
    <?php if ($potential === []): ?>
        <p class="muted">No search for this page sits at positions 4–20 with at least <?= PageOutlook::MIN_IMPRESSIONS ?> impressions in this window, so there is nothing to estimate.</p>
    <?php else: ?>
        <p class="muted">Google clicks per 28 days if this page reached position <?= PageOutlook::TARGET ?>, estimated with <?= $view->e($details['click_basis']) ?>. An estimate, not a promise: search features and intent change clicks.</p>
        <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Search</th><th scope="col" class="num">Position</th><th scope="col" class="num">Impressions</th><th scope="col" class="num">Clicks now</th><th scope="col" class="num">At #<?= PageOutlook::TARGET ?></th><th scope="col" class="num">More clicks</th></tr></thead><tbody>
        <?php foreach ($potential as $row): ?>
            <tr><td><?= $view->e($row['query']) ?></td><td class="num"><?= $view->e(number_format($row['position'], 1)) ?></td><td class="num"><?= $view->e(number_format($row['impressions'])) ?></td><td class="num"><?= $view->e(number_format($row['clicks'])) ?></td><td class="num"><?= $view->e(number_format($row['expected'])) ?></td><td class="num"><strong>+<?= $view->e(number_format($row['gain'])) ?></strong></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>

<section class="card" id="page-competitors">
    <h2>Pages ranking above it</h2>
    <?php if ($gap === null): ?>
        <p class="muted">No competitor analysis from the last 180 days included this page. Run <em>Why they outrank you</em> on a keyword topic this page ranks for, under <a href="<?= $view->e($base . '/keywords/topics') ?>">Keyword topics</a>.</p>
    <?php else: ?>
        <p class="muted">From the analysis of “<?= $view->e($gap['keyword']) ?>”<?= $gap['finished'] !== null ? ' on ' . $view->e(substr($gap['finished'], 0, 10)) : '' ?>: this page <?= $gap['own_position'] !== null ? 'ranked #' . $view->e($gap['own_position']) : 'was not in the top results' ?>, compared with <?= count($gap['above']) ?> <?= count($gap['above']) === 1 ? 'page' : 'pages' ?> above it (<?= $view->e(implode(', ', array_map(static fn(array $a): string => '#' . $a['position'] . ' ' . $a['domain'], $gap['above']))) ?>). <a href="<?= $view->e($base . '/keywords/topics/' . $gap['cluster'] . '/analysis') ?>">Open the analysis</a>.</p>
        <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Measure</th><th scope="col" class="num">This page</th><th scope="col" class="num">Typical above (median)</th><th scope="col">Status</th></tr></thead><tbody>
        <?php foreach ($gap['measures'] as $m): ?>
            <tr><td><?= $view->e($m['label']) ?></td><td class="num"><?= $view->e(number_format($m['own'])) ?></td><td class="num"><?= $view->e(number_format($m['median'], $m['median'] == floor($m['median']) ? 0 : 1)) ?></td><td><?= $m['behind'] ? '<span class="badge badge-warning">Behind</span>' : '<span class="muted">—</span>' ?></td></tr>
        <?php endforeach; ?>
            <tr><td>Last updated</td><td class="num"><?= $view->e($gap['own_modified'] ?? 'not stated') ?></td><td class="num"><?= $view->e($gap['newest_above'] ?? 'not stated') ?> <span class="muted small">(newest)</span></td><td><?= $gap['own_modified'] !== null && $gap['newest_above'] !== null && $gap['own_modified'] < $gap['newest_above'] ? '<span class="badge badge-warning">Older</span>' : '<span class="muted">—</span>' ?></td></tr>
        </tbody></table></div>
        <p class="small muted">Counts describe what the pages contain, not why they rank. Add what helps searchers; do not pad the page to match a number.</p>
    <?php endif; ?>
</section>

<section class="card" id="page-changes">
    <h2>Changes to this page and their results</h2>
    <?php if ($changes === []): ?>
        <p class="muted">No change to this page was recorded in the last year. <a href="<?= $view->e($base . '/annotations?' . http_build_query(['url' => $details['url']])) ?>">Record a change</a> with a measurement plan to see whether it worked.</p>
    <?php else: ?>
        <p class="muted">Changes recorded on this URL in the last year, newest first, read the same way as on <a href="<?= $view->e($base . '/annotations/results') ?>">What worked</a>.</p>
        <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Date</th><th scope="col">Change</th><th scope="col">Measured</th><th scope="col">Result</th><th scope="col" class="num">Effect</th></tr></thead><tbody>
        <?php foreach ($changes as $row): $a = $row['annotation']; ?>
            <tr><td><?= $view->e($a['date']) ?></td><td><a href="<?= $view->e($base . '/annotations/' . $a['annotation_id'] . '/impact') ?>"><?= $view->e($a['title']) ?></a></td><td><?= $view->e($row['metric'] !== null ? ResultsLedgerService::metricLabel($row['metric']) : '—') ?></td><td><span class="badge <?= $statusBadge($row['status']) ?>"><?= $view->e($row['label']) ?></span><?= $row['attribution'] === 'controlled' ? ' <span class="muted small">with control pages</span>' : '' ?><?= $row['afterwards'] ? ' <span class="muted small">measured afterwards</span>' : '' ?><?= $row['overlapping'] > 0 ? ' <span class="muted small">overlaps ' . $view->e((string) $row['overlapping']) . ' other ' . ($row['overlapping'] === 1 ? 'change' : 'changes') . '</span>' : '' ?></td><td class="num"><?= $row['effect'] !== null ? $view->e(sprintf('%+.1f', $row['effect']) . ' ' . $row['unit']) : '—' ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
