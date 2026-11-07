<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Domain\Keywords\ProjectKeyword $keyword
 * @var SeoControlCenter\Domain\Rankings\RankingSettings $settings
 * @var list<SeoControlCenter\Domain\Rankings\RankCheck> $checks
 * @var SeoControlCenter\Domain\SearchConsole\Metrics|null $searchConsole
 * @var SeoControlCenter\Domain\Shared\DateRange|null $searchConsoleRange
 * @var string|null $assetVersion
 */

$view->extend('layouts/app', ['title' => $keyword->keyword . ' · Rankings', 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], ['/projects/' . $project->publicId . '/rankings', 'Rankings'], [null, $keyword->keyword]]]);
$base = '/projects/' . $project->publicId;
$latest = $checks === [] ? null : $checks[count($checks) - 1];
$positions = array_values(array_filter(array_map(static fn($c): ?int => $c->position, $checks), static fn(?int $p): bool => $p !== null));
$m = $keyword->metrics;
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'rankings']) ?>
<div class="research-workspace research-rankings">
<div class="page-header">
    <h2><?= $view->e($keyword->keyword) ?></h2>
    <a class="button button-ghost" href="<?= $view->e($base) ?>/rankings">Back to rankings</a>
</div>
<p class="muted small"><?= $view->e($project->primaryDomain) ?> · <?= $view->e($keyword->locale->label()) ?> · Google <?= $view->e($settings->device->value) ?></p>

<div class="stat-grid">
    <section class="card stat">
        <h2 class="stat-label">Position</h2>
        <p class="stat-value"><?= $latest === null ? '—' : $view->e($latest->position ?? '>100') ?></p>
        <?php if ($latest !== null): ?><p class="stat-delta muted">checked <?= $view->e($latest->date) ?></p><?php endif; ?>
    </section>
    <section class="card stat">
        <h2 class="stat-label">Best (12 months)</h2>
        <p class="stat-value"><?= $positions === [] ? '—' : $view->e(min($positions)) ?></p>
    </section>
    <section class="card stat">
        <h2 class="stat-label">Search volume</h2>
        <p class="stat-value"><?= $m?->searchVolume !== null ? $view->e(number_format($m->searchVolume)) : '—' ?></p>
        <?php if ($m?->cpc !== null): ?><p class="stat-delta muted">CPC $<?= $view->e(number_format($m->cpc, 2)) ?></p><?php endif; ?>
    </section>
    <section class="card stat">
        <h2 class="stat-label">Search Console clicks</h2>
        <p class="stat-value"><?= $searchConsole !== null ? $view->e(number_format($searchConsole->clicks)) : '—' ?></p>
        <p class="stat-delta muted">
            <?php if ($searchConsole !== null && $searchConsoleRange !== null): ?>
                <?= $view->e(number_format($searchConsole->impressions)) ?> impressions, avg. position <?= $view->e(number_format($searchConsole->position, 1)) ?> · <?= $view->e($searchConsoleRange->fromString()) ?> – <?= $view->e($searchConsoleRange->toString()) ?>
            <?php else: ?>
                no Search Console data for this exact query
            <?php endif; ?>
        </p>
    </section>
</div>

<?php if (count($checks) >= 2): ?>
    <?= $view->partial('partials/line-chart', [
        'title' => 'Position history',
        'annotations' => $annotations,
        'project' => $project,
        // Positions beyond the checked top 100 are drawn at 100.
        'series' => array_map(static fn($c): array => [$c->date, (float) ($c->position ?? 100)], $checks),
        'range' => new SeoControlCenter\Domain\Shared\DateRange($checks[0]->date, $checks[count($checks) - 1]->date),
        'invert' => true,
        'wide' => true,
    ]) ?>
    <script type="module" src="/assets/js/line-chart.js?v=<?= $view->e($assetVersion ?? '0') ?>"></script>
<?php endif; ?>

<section class="card">
    <h2>Checks</h2>
    <?php if ($checks === []): ?>
        <p class="muted">This keyword has not been checked yet<?= $settings->enabled ? '; it is included in the next check.' : '. Switch rank tracking on to start.' ?></p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table table-dense">
                <thead><tr><th scope="col">Date</th><th scope="col">Position</th><th scope="col">Ranking URL</th><th scope="col">SERP features</th><th scope="col">Top 3</th></tr></thead>
                <tbody>
                    <?php foreach (array_reverse($checks) as $check): ?>
                        <tr>
                            <td><?= $view->e($check->date) ?></td>
                            <td><?= $view->e($check->position ?? '>100') ?></td>
                            <td class="muted small"><?= $check->url !== null ? $view->e(mb_strimwidth($check->url, 0, 80, '…')) : '—' ?></td>
                            <td class="muted small"><?= $view->e(str_replace('_', ' ', implode(', ', $check->features))) ?></td>
                            <td class="muted small"><?= $view->e(implode(', ', array_map(static fn(array $r): string => $r['domain'], array_slice($check->top, 0, 3)))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

</div>
