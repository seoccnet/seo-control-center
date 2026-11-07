<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var string|null $assetVersion
 * @var SeoControlCenter\Modules\Bing\BingSiteLink|null $link
 * @var array{first: string, last: string}|null $span
 * @var string $rangeKey
 * @var array<string, int> $ranges
 * @var bool $canImport
 * @var string|null $staleSince newest Bing day, when it is more than two weeks old
 * @var array{
 *     range: SeoControlCenter\Domain\Shared\DateRange,
 *     totals: SeoControlCenter\Domain\SearchConsole\Metrics,
 *     previous: SeoControlCenter\Domain\SearchConsole\Metrics,
 *     hasPrevious: bool,
 *     days: list<array{0: string, 1: SeoControlCenter\Domain\SearchConsole\Metrics|null}>,
 *     weeks: int,
 *     queries: list<SeoControlCenter\Domain\SearchConsole\DimensionRow>,
 *     pages: list<SeoControlCenter\Domain\SearchConsole\DimensionRow>,
 *     crawl: list<SeoControlCenter\Modules\Bing\BingCrawlDay>,
 *     links: list<SeoControlCenter\Modules\Bing\BingLinkCount>,
 *     linkTotals: array{pages: int, links: int}
 * }|null $data
 */
$view->extend('layouts/app', ['title' => 'Bing performance · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], ['/projects/' . $project->publicId . '/performance', 'Performance'], [null, 'Bing']]]);
$base = '/projects/' . $project->publicId;
$num = static fn(float|int $v): string => number_format((float) $v);
$pct = static fn(float $v): string => number_format($v * 100, 1) . ' %';

if ($link === null) {
    $meta = 'Bing not linked';
    $metaStatus = 'warn';
} elseif ($link->status === 'error') {
    $meta = 'Last Bing import failed';
    $metaStatus = 'error';
} else {
    $meta = $link->siteUrl . ($link->lastSuccessAt !== null ? ' · imported ' . $link->lastSuccessAt . ' UTC' : ' · import queued');
    $metaStatus = 'ok';
}
$actions = null;
if ($link !== null && $canImport) {
    $actions = '<form method="post" action="' . $view->e($base) . '/performance/bing/import" class="inline-form">' . $view->csrfField()
        . '<button type="submit" class="button">' . $view->icon('refresh') . 'Import now</button></form>';
}
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'performance', 'meta' => $meta, 'metaStatus' => $metaStatus, 'actions' => $actions]) ?>
<div class="insight-workspace performance-workspace">

<div class="section-header performance-toolbar">
    <?= $view->partial('partials/performance-sources', ['base' => $base, 'current' => 'bing']) ?>
    <?php if ($data !== null): ?>
        <?= $view->partial('partials/range-picker', ['ranges' => $ranges, 'current' => $rangeKey, 'href' => $base . '/performance/bing']) ?>
    <?php endif; ?>
</div>

<?php if ($link !== null && $link->status === 'error' && $link->lastError !== null): ?>
    <p class="notice notice-error" role="alert">Last Bing import failed: <?= $view->e($link->lastError) ?></p>
<?php endif; ?>

<?php if ($link === null): ?>
    <section class="card empty">
        <h2>Connect Bing Webmaster Tools</h2>
        <p>Bing's index also powers Yahoo, DuckDuckGo and Ecosia, and the web search of ChatGPT and Copilot. Link this project to a site in Bing Webmaster Tools to see its clicks, impressions, queries and pages.</p>
        <p><a class="button" href="<?= $view->e($base) ?>/bing">Link a Bing site</a></p>
    </section>
<?php elseif ($data === null): ?>
    <section class="card empty">
        <h2>No Bing data yet</h2>
        <p>Bing data appears here after the first import, which runs in the background with cron. Bing provides about six months of history.</p>
    </section>
<?php else: ?>
    <?php $range = $data['range']; ?>
    <?php if ($staleSince !== null): ?>
        <p class="notice notice-warning" role="status">Bing's newest data for this site is from <?= $view->e($staleSince) ?>. Bing usually reports impressions within a few days, so the site has had none since. Check its indexing in Bing Webmaster Tools (Site Explorer, URL Inspection).</p>
    <?php endif; ?>
    <p class="muted small"><?= $view->e($range->fromString()) ?> – <?= $view->e($range->toString()) ?> · Bing dates (Pacific Time) · <?= $view->e($link->siteUrl) ?></p>

    <section id="performance-overview" class="performance-section" aria-labelledby="performance-overview-title">
        <div class="section-header"><div><p class="portfolio-eyebrow">Period overview</p><h2 id="performance-overview-title">Search performance</h2><p class="muted small">Your key metrics, compared with the previous period.</p></div><nav class="performance-outline" aria-label="Performance sections"><a href="#performance-trends">Trends</a><a href="#performance-search">Queries &amp; pages</a><a href="#performance-daily">Daily data</a></nav></div>
    <?= $view->partial('partials/search-kpis', ['totals' => $data['totals'], 'previous' => $data['previous'], 'hasPrevious' => $data['hasPrevious'], 'range' => $range, 'showPosition' => false]) ?>
    </section>

    <?= $view->partial('partials/performance-trends', ['data' => $data, 'range' => $range, 'annotations' => $annotations, 'project' => $project, 'showPosition' => false]) ?>



    <section id="performance-search" class="performance-section" aria-labelledby="performance-search-title">
        <div class="section-header"><div><p class="portfolio-eyebrow">Search breakdown</p><h2 id="performance-search-title">Queries &amp; landing pages</h2><p class="muted small">See which searches and pages bring people to your site.</p></div></div>
    <div class="table-grid">
        <?php foreach ([['Top queries', 'Query', $data['queries'], false], ['Top pages', 'Page', $data['pages'], true]] as [$title, $column, $rows, $isUrl]): ?>
            <section class="card">
                <div class="card-header">
                    <h2><?= $view->e($title) ?></h2>
                    <?php if ($rows !== []): ?><a class="button button-ghost button-small" href="<?= $view->e($base . '/performance/bing/export?dimension=' . strtolower($column) . '&range=' . $rangeKey) ?>"><?= $view->icon('download') ?>Export CSV</a><?php endif; ?>
                </div>
                <?php if ($rows === []): ?>
                    <p class="muted">No weekly data for this range yet.</p>
                <?php else: ?>
                    <div class="table-scroll"><table class="table table-dense numeric">
                            <thead><tr><th scope="col"><?= $view->e($column) ?></th><th scope="col">Clicks</th><th scope="col">Impr.</th><th scope="col">CTR</th><th scope="col">Pos.</th></tr></thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td class="key-cell"><?php if ($isUrl && preg_match('#^https?://#i', $row->key) === 1): ?><a href="<?= $view->e($row->key) ?>" rel="noopener noreferrer" target="_blank"><?= $view->e(preg_replace('#^https?://[^/]+#', '', $row->key) ?: '/') ?></a><?php else: ?><?= $view->e($row->key) ?><?php endif; ?></td>
                                        <td><?= $view->e($num($row->metrics->clicks)) ?></td>
                                        <td><?= $view->e($num($row->metrics->impressions)) ?></td>
                                        <td><?= $view->e($pct($row->metrics->ctr())) ?></td>
                                        <td><?= $row->metrics->impressions > 0 ? $view->e(number_format($row->metrics->position, 1)) : '–' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table></div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
    <p class="muted small">Bing reports queries and pages per week, so these tables cover the <?= $view->e($data['weeks']) ?> full week<?= $data['weeks'] === 1 ? '' : 's' ?> ending in this range, and daily totals have no average position.</p>
    </section>
    <?php if ($data['crawl'] !== [] || $data['links'] !== []): ?>
    <section id="performance-crawl" class="performance-section" aria-labelledby="performance-crawl-title">
        <div class="section-header"><div><p class="portfolio-eyebrow">Crawl &amp; index</p><h2 id="performance-crawl-title">How Bing crawls your site</h2><p class="muted small">Bing's own crawl counts for this period, and the pages other sites link to.</p></div></div>
        <?php if ($data['crawl'] !== []):
            $latest = $data['crawl'][count($data['crawl']) - 1];
            $first = $data['crawl'][0];
            $crawled = array_sum(array_map(static fn($d): int => $d->crawledPages, $data['crawl']));
            $errors = array_sum(array_map(static fn($d): int => $d->crawlErrors, $data['crawl']));
            $blocked = array_sum(array_map(static fn($d): int => $d->blockedByRobots, $data['crawl']));
        ?>
        <div class="stat-grid">
            <section class="stat"><h2 class="stat-label">Pages in Bing's index</h2><p class="stat-value"><?= $view->e($num($latest->inIndex)) ?></p><p class="stat-delta"><?php $change = $latest->inIndex - $first->inIndex; ?><?= $change === 0 ? 'no change' : $view->e(sprintf('%+d', $change)) ?> <span class="muted">since <?= $view->e($first->date) ?></span></p></section>
            <section class="stat"><h2 class="stat-label">Pages crawled</h2><p class="stat-value"><?= $view->e($num($crawled)) ?></p><p class="stat-delta"><span class="muted">in this period</span></p></section>
            <section class="stat"><h2 class="stat-label">Crawl errors</h2><p class="stat-value"><?= $view->e($num($errors)) ?></p><p class="stat-delta"><span class="muted"><?= $crawled > 0 ? $view->e($pct($errors / $crawled)) . ' of crawled pages' : 'in this period' ?></span></p></section>
            <section class="stat"><h2 class="stat-label">Blocked by robots.txt</h2><p class="stat-value"><?= $view->e($num($blocked)) ?></p><p class="stat-delta"><span class="muted">in this period</span></p></section>
        </div>
        <details class="card insight-data"><summary>Daily crawl data as a table</summary>
            <div class="table-scroll"><table class="table table-dense numeric">
                <thead><tr><th scope="col">Date</th><th scope="col">In index</th><th scope="col">Crawled</th><th scope="col">Errors</th><th scope="col">2xx</th><th scope="col">Redirects</th><th scope="col">4xx</th><th scope="col">5xx</th><th scope="col">Robots.txt</th></tr></thead>
                <tbody>
                    <?php foreach (array_reverse($data['crawl']) as $d): ?>
                        <tr><td><?= $view->e($d->date) ?></td><td><?= $view->e($num($d->inIndex)) ?></td><td><?= $view->e($num($d->crawledPages)) ?></td><td><?= $view->e($num($d->crawlErrors)) ?></td><td><?= $view->e($num($d->code2xx)) ?></td><td><?= $view->e($num($d->code3xx)) ?></td><td><?= $view->e($num($d->code4xx)) ?></td><td><?= $view->e($num($d->code5xx)) ?></td><td><?= $view->e($num($d->blockedByRobots)) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </details>
        <?php endif; ?>
        <?php if ($data['links'] !== []): ?>
        <section class="card">
            <div class="card-header"><h2>Most linked pages</h2></div>
            <p class="muted small"><?= $view->e($num($data['linkTotals']['links'])) ?> inbound links to <?= $view->e($num($data['linkTotals']['pages'])) ?> pages, as Bing currently counts them (not limited to this period). Free and Bing-only; Backlinks has the fuller picture.</p>
            <div class="table-scroll"><table class="table table-dense numeric">
                <thead><tr><th scope="col">Page</th><th scope="col">Inbound links</th></tr></thead>
                <tbody>
                    <?php foreach ($data['links'] as $l): ?>
                        <tr><td class="key-cell"><a href="<?= $view->e($l->url) ?>" rel="noopener noreferrer" target="_blank"><?= $view->e(preg_replace('#^https?://[^/]+#', '', $l->url) ?: '/') ?></a></td><td><?= $view->e($num($l->links)) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>
        <?php endif; ?>
    </section>
    <?php endif; ?>
    <section class="performance-section" aria-label="Daily data">
    <details id="performance-daily" class="card insight-data">
        <summary>Daily data as a table</summary>
        <div class="table-scroll"><table class="table table-dense numeric">
            <thead><tr><th scope="col">Date</th><th scope="col">Clicks</th><th scope="col">Impressions</th><th scope="col">CTR</th></tr></thead>
            <tbody>
                <?php foreach (array_reverse($data['days']) as [$date, $m]): ?>
                    <tr>
                        <td><?= $view->e($date) ?></td>
                        <td><?= $m === null ? '–' : $view->e($num($m->clicks)) ?></td>
                        <td><?= $m === null ? '–' : $view->e($num($m->impressions)) ?></td>
                        <td><?= $m !== null && $m->impressions > 0 ? $view->e($pct($m->ctr())) : '–' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </details>
    </section>
    <script type="module" src="/assets/js/line-chart.js?v=<?= $view->e($assetVersion ?? '0') ?>"></script>
<?php endif; ?>

</div>
