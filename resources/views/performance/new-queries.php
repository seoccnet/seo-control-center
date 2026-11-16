<?php

declare(strict_types=1);

/**
 * New Search Console queries (1.6.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var int $days
 * @var SeoControlCenter\Domain\Shared\DateRange|null $range
 * @var SeoControlCenter\Domain\Shared\DateRange|null $previous
 * @var SeoControlCenter\Domain\Shared\DateRange|null $lookback
 * @var bool $complete
 * @var int $total
 * @var list<array{query: string, metrics: SeoControlCenter\Domain\SearchConsole\Metrics, firstTime: bool, tracked: bool}> $rows
 * @var bool $canTrack
 * @var SeoControlCenter\Domain\Keywords\KeywordLocale $locale
 */

$base = '/projects/' . $project->publicId;
$self = $base . '/performance/new-queries';
$view->extend('layouts/app', ['title' => 'New queries · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [$base . '/performance', 'Performance'], [null, 'New queries']]]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'performance']);
$trackable = array_values(array_filter($rows, static fn(array $r): bool => !$r['tracked']));
?>
<div class="insight-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow"><a href="<?= $view->e($base . '/performance') ?>">Performance</a></p><h2>New queries</h2><p class="muted">Searches your site started to appear for: impressions in the latest period and none in the period before. Early signal of new demand, or of pages Google started to show for new topics.</p></div></header>

<?php if ($range === null || $previous === null): ?>
<section class="card"><p>No Search Console data yet. <a href="<?= $view->e($base . '/search-console') ?>">Link a property</a> and import it first.</p></section>
<?php else: ?>
<section class="card" aria-labelledby="new-queries-heading">
    <div class="card-header"><h2 id="new-queries-heading"><?= $view->e(number_format($total)) ?> new queries</h2>
        <nav class="segmented" aria-label="Period"><?php foreach (SeoControlCenter\Modules\SearchConsole\NewQueriesService::PERIODS as $period): ?><a href="<?= $view->e($self . '?days=' . $period) ?>"<?= $period === $days ? ' aria-current="page"' : '' ?>><?= $view->e($period . ' days') ?></a><?php endforeach; ?></nav>
    </div>
    <p class="muted"><?= $view->e($range->fromString() . ' to ' . $range->toString()) ?> against <?= $view->e($previous->fromString() . ' to ' . $previous->toString()) ?>. <strong>First time</strong> means no impressions in the year before either<?= $lookback === null ? ' (no older data to check)' : ' (' . $view->e($lookback->fromString()) . ' on)' ?>. At least 2 impressions; the <?= $view->e((string) SeoControlCenter\Modules\SearchConsole\NewQueriesService::LIMIT) ?> with the most impressions are shown.</p>
    <?php if (!$complete): ?><p class="notice notice-warning">Some days of these periods were not imported completely, so a few queries may only look new.</p><?php endif; ?>
    <?php if ($rows === []): ?>
    <p>No new queries in this period.</p>
    <?php else: ?>
    <form method="post" action="<?= $view->e($base . '/keywords') ?>">
        <?= $view->csrfField() ?>
        <input type="hidden" name="location" value="<?= $view->e((string) $locale->locationCode) ?>"><input type="hidden" name="language" value="<?= $view->e($locale->languageCode) ?>">
        <div class="table-scroll"><table class="table table-dense numeric">
            <thead><tr><th scope="col">Query</th><th scope="col">Impressions</th><th scope="col">Clicks</th><th scope="col">Position</th><th scope="col">Seen before</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?php if ($canTrack && !$row['tracked']): ?><label><input type="checkbox" name="suggested[]" value="<?= $view->e($row['query']) ?>"> <?= $view->e($row['query']) ?></label><?php else: ?><?= $view->e($row['query']) ?><?= $row['tracked'] ? ' <span class="badge">Tracked</span>' : '' ?><?php endif; ?></td>
                    <td><?= $view->e(number_format($row['metrics']->impressions)) ?></td>
                    <td><?= $view->e(number_format($row['metrics']->clicks)) ?></td>
                    <td><?= $view->e(number_format($row['metrics']->position, 1)) ?></td>
                    <td><?= $row['firstTime'] ? '<span class="badge badge-info">First time</span>' : 'Back after a break' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if ($canTrack && $trackable !== []): ?><div class="actions"><button type="submit" class="button button-small">Track selected as keywords</button> <span class="muted small">in <?= $view->e($locale->label()) ?></span></div><?php endif; ?>
    </form>
    <?php endif; ?>
</section>
<?php endif; ?>
</div>
