<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Domain\Competitors\Competitor $competitor
 * @var SeoControlCenter\Domain\Rankings\RankingDay|null $latest
 * @var list<SeoControlCenter\Domain\Competitors\KeywordComparison> $comparisons
 * @var array{all: int, gaps: int, behind: int, ahead: int} $counts
 * @var string $filter
 */
$view->extend('layouts/app', ['title' => $competitor->domain . ' · Competitors', 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], ['/projects/' . $project->publicId . '/competitors', 'Competitors'], [null, $competitor->domain]]]);
$base = '/projects/' . $project->publicId;
$self = $base . '/competitors/' . $competitor->publicId;
$position = static fn(?int $p): string => $p === null ? '>100' : (string) $p;
$labels = ['all' => 'All keywords', 'gaps' => 'Gaps', 'behind' => 'They are ahead', 'ahead' => 'You are ahead'];
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'competitors']) ?>
<div class="research-workspace research-competitors">
<div class="page-header">
    <h2><?= $view->e($project->primaryDomain) ?> vs <?= $view->e($competitor->domain) ?></h2>
    <a class="button button-ghost" href="<?= $view->e($base) ?>/competitors">Back to competitors</a>
</div>

<?php if ($latest === null): ?>
    <section class="card"><p class="muted">No rank check yet. The comparison appears after the first check of this project's keywords.</p></section>
<?php else: ?>
    <section class="card">
        <nav class="filter-row" aria-label="Filter keywords">
            <?php foreach ($labels as $key => $label): ?>
                <a class="chip" href="<?= $view->e($self) ?><?= $key === 'all' ? '' : '?view=' . $view->e($key) ?>"<?= $key === $filter ? ' aria-current="true"' : '' ?>><?= $view->e($label) ?> (<?= $view->e($counts[$key]) ?>)</a>
            <?php endforeach; ?>
            <span class="muted small">check of <?= $view->e($latest->date) ?> (UTC)</span>
        </nav>

        <?php if ($comparisons === []): ?>
            <p class="muted">No keywords in this view.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="table table-dense numeric">
                    <thead><tr><th scope="col">Keyword</th><th scope="col">Volume</th><th scope="col">You</th><th scope="col"><?= $view->e($competitor->domain) ?></th><th scope="col">Difference</th><th scope="col">Their URL</th></tr></thead>
                    <tbody>
                        <?php foreach ($comparisons as $c): ?>
                            <tr>
                                <td><a href="<?= $view->e($base) ?>/rankings/<?= $view->e($c->keywordPublicId) ?>"><?= $view->e($c->keyword) ?></a><?= $c->isGap() ? ' <span class="badge badge-warning">gap</span>' : '' ?></td>
                                <td><?= $c->searchVolume !== null ? $view->e(number_format($c->searchVolume)) : '—' ?></td>
                                <td><?= $view->e($position($c->own)) ?></td>
                                <td><?= $view->e($position($c->theirs)) ?></td>
                                <td><?= $c->lead() === 0 ? '<span class="delta">even</span>' : sprintf('<span class="delta %s">%s</span>', $c->lead() > 0 ? 'delta-good' : 'delta-bad', $view->e(sprintf('%+d', $c->lead()))) ?></td>
                                <td class="muted small"><?= $c->theirUrl !== null ? $view->e(mb_strimwidth((string) (parse_url($c->theirUrl, PHP_URL_PATH) ?: '/'), 0, 60, '…')) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="muted small">Difference: positive = you rank better. Not ranking counts as position 101.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

</div>
