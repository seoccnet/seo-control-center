<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var string|null $assetVersion
 * @var array{connection: SeoControlCenter\Domain\Integrations\Connection, property: SeoControlCenter\Domain\SearchConsole\SearchConsoleProperty, linked_at: string}|null $link
 * @var SeoControlCenter\Domain\SearchConsole\SyncState|null $sync
 * @var int $pendingDays
 * @var array{first: string, last: string}|null $span
 * @var string $rangeKey
 * @var array<string, int> $ranges
 * @var bool $canImport
 * @var array{
 *     range: SeoControlCenter\Domain\Shared\DateRange,
 *     previousRange: SeoControlCenter\Domain\Shared\DateRange,
 *     totals: SeoControlCenter\Domain\SearchConsole\Metrics,
 *     previous: SeoControlCenter\Domain\SearchConsole\Metrics,
 *     hasPrevious: bool,
 *     days: list<array{0: string, 1: SeoControlCenter\Domain\SearchConsole\Metrics|null}>,
 *     queries: list<SeoControlCenter\Domain\SearchConsole\DimensionRow>,
 *     pages: list<SeoControlCenter\Domain\SearchConsole\DimensionRow>,
 *     devices: list<SeoControlCenter\Domain\SearchConsole\DimensionRow>,
 *     countries: list<SeoControlCenter\Domain\SearchConsole\DimensionRow>,
 *     fresh: list<SeoControlCenter\Domain\SearchConsole\FreshDay>,
 *     today: string,
 *     surfaces: list<array{type: SeoControlCenter\Domain\SearchConsole\SearchType, totals: SeoControlCenter\Domain\SearchConsole\Metrics, previous: SeoControlCenter\Domain\SearchConsole\Metrics}>
 * }|null $data
 */
$view->extend('layouts/app', ['title' => 'Performance · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [null, 'Performance']]]);
$base = '/projects/' . $project->publicId;
$num = static fn(float|int $v): string => number_format((float) $v);
$pct = static fn(float $v): string => number_format($v * 100, 1) . ' %';

if ($link === null) {
    $meta = 'Search Console not linked';
    $metaStatus = 'warn';
} elseif ($sync !== null && $sync->status === 'error') {
    $meta = 'Last import failed';
    $metaStatus = 'error';
} else {
    $meta = $link['property']->label() . ($sync?->lastSuccessAt !== null ? ' · imported ' . $sync->lastSuccessAt . ' UTC' : ' · import queued')
        . ($pendingDays > 0 ? ' · ' . $num($pendingDays) . ' day(s) still importing' : '');
    $metaStatus = 'ok';
}
$actions = null;
if ($link !== null && $canImport) {
    $actions = '<form method="post" action="' . $view->e($base) . '/performance/import" class="inline-form">' . $view->csrfField()
        . '<button type="submit" class="button">' . $view->icon('refresh') . 'Import now</button></form>';
}
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'performance', 'meta' => $meta, 'metaStatus' => $metaStatus, 'actions' => $actions]) ?>
<div class="insight-workspace performance-workspace">

<div class="section-header performance-toolbar">
    <?= $view->partial('partials/performance-sources', ['base' => $base, 'current' => 'google']) ?>
    <?php if ($data !== null): ?>
        <?= $view->partial('partials/range-picker', ['ranges' => $ranges, 'current' => $rangeKey, 'href' => $base . '/performance']) ?>
    <?php endif; ?>
</div>

<?php if ($sync !== null && $sync->status === 'error' && $sync->lastError !== null): ?>
    <p class="notice notice-error" role="alert">Last import failed: <?= $view->e($sync->lastError) ?></p>
<?php endif; ?>

<?php if ($link === null): ?>
    <section class="card empty">
        <h2>Connect Search Console</h2>
        <p>Link this project to a Google Search Console property to see clicks, impressions and positions for up to 16 months.</p>
        <p><a class="button" href="<?= $view->e($base) ?>/search-console">Link a property</a></p>
    </section>
<?php elseif ($data === null): ?>
    <section class="card empty">
        <h2>No data yet</h2>
        <p>Search Console data appears here after the first import. It runs in the background with cron; the most recent days arrive first, then up to 16 months of history.</p>
    </section>
<?php else: ?>
    <?php $range = $data['range']; ?>
    <div class="section-header">
        <p class="muted small"><?= $view->e($range->fromString()) ?> – <?= $view->e($range->toString()) ?> · Search Console dates (Pacific Time)</p>

    </div>

    <section id="performance-overview" class="performance-section" aria-labelledby="performance-overview-title">
        <div class="section-header"><div><p class="portfolio-eyebrow">Period overview</p><h2 id="performance-overview-title">Search performance</h2><p class="muted small">Your key metrics, compared with the previous period.</p></div><nav class="performance-outline" aria-label="Performance sections"><a href="#performance-trends">Trends</a><a href="#performance-search">Queries &amp; pages</a><a href="#performance-audience">Audience</a><a href="#performance-daily">Daily data</a></nav></div>
    <?= $view->partial('partials/search-kpis', ['totals' => $data['totals'], 'previous' => $data['previous'], 'hasPrevious' => $data['hasPrevious'], 'range' => $range]) ?>
    </section>

    <?php if ($data['fresh'] !== []): ?>
    <section id="performance-fresh" class="performance-section" aria-labelledby="performance-fresh-title">
        <div class="card">
            <div class="card-header"><div><p class="portfolio-eyebrow">Preliminary</p><h2 id="performance-fresh-title">Latest days, not final yet</h2></div></div>
            <p class="muted small">Google's early numbers for the days after <?= $view->e($range->toString()) ?>. They usually change a little until Google finalizes them; then they move into the figures above. Charts, comparisons and opportunities use final data only.</p>
            <div class="table-scroll"><table class="table table-dense numeric">
                <thead><tr><th scope="col">Date</th><th scope="col">Clicks</th><th scope="col">Impressions</th><th scope="col">CTR</th><th scope="col">Position</th></tr></thead>
                <tbody>
                    <?php foreach (array_reverse($data['fresh']) as $day): ?>
                        <tr>
                            <td><?= $view->e($day->date) ?><?php if (!$day->complete): ?> <span class="muted small"><?= $day->date === $data['today'] ? '(today so far)' : '(still collecting)' ?></span><?php endif; ?></td>
                            <td><?= $view->e($num($day->metrics->clicks)) ?></td>
                            <td><?= $view->e($num($day->metrics->impressions)) ?></td>
                            <td><?= $view->e($pct($day->metrics->ctr())) ?></td>
                            <td><?= $day->metrics->impressions > 0 ? $view->e(number_format($day->metrics->position, 1)) : '–' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($data['surfaces'] !== []): ?>
    <section id="performance-surfaces" class="performance-section" aria-labelledby="performance-surfaces-title">
        <div class="section-header"><div><p class="portfolio-eyebrow">Beyond web search</p><h2 id="performance-surfaces-title">Other Google surfaces</h2><p class="muted small">Traffic Search Console reports separately from web search, for the same period. These clicks are not part of the figures above.</p></div></div>
        <div class="card"><div class="table-scroll"><table class="table table-dense numeric">
            <thead><tr><th scope="col">Surface</th><th scope="col">Clicks</th><th scope="col">Previous</th><th scope="col">Impressions</th><th scope="col">CTR</th><th scope="col">Position</th></tr></thead>
            <tbody>
                <?php foreach ($data['surfaces'] as $surface): $t = $surface['totals']; ?>
                    <tr>
                        <td class="key-cell"><?= $view->e($surface['type']->label()) ?></td>
                        <td><?= $view->e($num($t->clicks)) ?></td>
                        <td><?= $data['hasPrevious'] ? $view->e($num($surface['previous']->clicks)) : '–' ?></td>
                        <td><?= $view->e($num($t->impressions)) ?></td>
                        <td><?= $t->impressions > 0 ? $view->e($pct($t->ctr())) : '–' ?></td>
                        <td><?= $surface['type']->hasPosition() && $t->impressions > 0 ? $view->e(number_format($t->position, 1)) : '–' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div></div>
        <p class="muted small">Discover and Google News have no search position. The News tab is the "News" filter in Google Search; Google News is news.google.com and the Google News app.</p>
    </section>
    <?php endif; ?>

    <?= $view->partial('partials/performance-trends', ['data' => $data, 'range' => $range, 'annotations' => $annotations, 'project' => $project, 'showPosition' => true]) ?>

    <?php
    $tables = [
        ['Top queries', 'Query', $data['queries'], false],
        ['Top pages', 'Page', $data['pages'], true],
        ['Devices', 'Device', $data['devices'], false],
        ['Countries', 'Country', $data['countries'], false],
    ];
    ?>
    <section id="performance-search" class="performance-section" aria-labelledby="performance-search-title">
        <div class="section-header"><div><p class="portfolio-eyebrow">Search breakdown</p><h2 id="performance-search-title">Queries &amp; landing pages</h2><p class="muted small">See which searches and pages bring people to your site.</p></div></div>
    <div class="table-grid">
        <?php foreach (array_slice($tables, 0, 2) as [$title, $column, $rows, $isUrl]): ?>
            <section class="card">
                <div class="card-header">
                    <h2><?= $view->e($title) ?></h2>
                    <?php if ($rows !== [] && in_array($column, ['Query', 'Page'], true)): ?><a class="button button-ghost button-small" href="<?= $view->e($base . '/performance/export?dimension=' . strtolower($column) . '&range=' . $rangeKey) ?>"><?= $view->icon('download') ?>Export CSV</a><?php endif; ?>
                </div>
                <?php if ($rows === []): ?>
                    <p class="muted">No rows imported for this range yet.</p>
                <?php else: ?>
                    <div class="table-scroll"><table class="table table-dense numeric">
                            <thead><tr><th scope="col"><?= $view->e($column) ?></th><th scope="col">Clicks</th><th scope="col">Impr.</th><th scope="col">CTR</th><th scope="col">Pos.</th></tr></thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td class="key-cell"><?php if ($isUrl && preg_match('#^https?://#i', $row->key) === 1): ?><a href="<?= $view->e($row->key) ?>" rel="noopener noreferrer" target="_blank"><?= $view->e(preg_replace('#^https?://[^/]+#', '', $row->key) ?: '/') ?></a><?php else: ?><?= $view->e($column === 'Country' ? strtoupper($row->key) : $row->key) ?><?php endif; ?></td>
                                        <td><?= $view->e($num($row->metrics->clicks)) ?></td>
                                        <td><?= $view->e($num($row->metrics->impressions)) ?></td>
                                        <td><?= $view->e($pct($row->metrics->ctr())) ?></td>
                                        <td><?= $view->e(number_format($row->metrics->position, 1)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table></div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
    <p class="muted small">Query rows exclude anonymized queries, so they add up to less than the totals above. </p>
    </section>
    <section id="performance-audience" class="performance-section" aria-labelledby="performance-audience-title">
        <div class="section-header"><div><p class="portfolio-eyebrow">Audience</p><h2 id="performance-audience-title">Where your clicks come from</h2><p class="muted small">Compare devices and countries across the imported results.</p></div></div>
    <div class="insight-two-column">
    <?php foreach ([['devices','Audience by device'],['countries','Audience by country']] as [$dimension,$heading]): ?>
    <?= $view->partial('partials/insight-bars', ['title' => $heading, 'eyebrow' => 'Audience', 'note' => 'Clicks from the top imported rows in this period. Bars compare listed values.', 'bars' => array_map(static fn($row): array => ['label' => $dimension === 'countries' ? strtoupper($row->key) : ucfirst($row->key), 'value' => $row->metrics->clicks], array_slice($data[$dimension],0,5))]) ?>
    <?php endforeach; ?>
    </div>
        <details class="card insight-data"><summary>View all device and country metrics</summary>
    <div class="table-grid">
        <?php foreach (array_slice($tables, 2) as [$title, $column, $rows, $isUrl]): ?>
            <section class="card">
                <div class="card-header">
                    <h2><?= $view->e($title) ?></h2>
                    <?php if ($rows !== [] && in_array($column, ['Query', 'Page'], true)): ?><a class="button button-ghost button-small" href="<?= $view->e($base . '/performance/export?dimension=' . strtolower($column) . '&range=' . $rangeKey) ?>"><?= $view->icon('download') ?>Export CSV</a><?php endif; ?>
                </div>
                <?php if ($rows === []): ?>
                    <p class="muted">No rows imported for this range yet.</p>
                <?php else: ?>
                    <div class="table-scroll"><table class="table table-dense numeric">
                            <thead><tr><th scope="col"><?= $view->e($column) ?></th><th scope="col">Clicks</th><th scope="col">Impr.</th><th scope="col">CTR</th><th scope="col">Pos.</th></tr></thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td class="key-cell"><?php if ($isUrl && preg_match('#^https?://#i', $row->key) === 1): ?><a href="<?= $view->e($row->key) ?>" rel="noopener noreferrer" target="_blank"><?= $view->e(preg_replace('#^https?://[^/]+#', '', $row->key) ?: '/') ?></a><?php else: ?><?= $view->e($column === 'Country' ? strtoupper($row->key) : $row->key) ?><?php endif; ?></td>
                                        <td><?= $view->e($num($row->metrics->clicks)) ?></td>
                                        <td><?= $view->e($num($row->metrics->impressions)) ?></td>
                                        <td><?= $view->e($pct($row->metrics->ctr())) ?></td>
                                        <td><?= $view->e(number_format($row->metrics->position, 1)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table></div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
        </details>
    <p class="muted small">Countries use ISO 3166 alpha-3 codes.</p>
    </section>
    <section class="performance-section" aria-label="Daily data">
    <details id="performance-daily" class="card insight-data">
        <summary>Daily data as a table</summary>
        <div class="table-scroll"><table class="table table-dense numeric">
            <thead><tr><th scope="col">Date</th><th scope="col">Clicks</th><th scope="col">Impressions</th><th scope="col">CTR</th><th scope="col">Position</th></tr></thead>
            <tbody>
                <?php foreach (array_reverse($data['days']) as [$date, $m]): ?>
                    <tr>
                        <td><?= $view->e($date) ?></td>
                        <?php if ($m === null): ?>
                            <td>–</td><td>–</td><td>–</td><td>–</td>
                        <?php else: ?>
                            <td><?= $view->e($num($m->clicks)) ?></td><td><?= $view->e($num($m->impressions)) ?></td>
                            <td><?= $view->e($pct($m->ctr())) ?></td><td><?= $view->e(number_format($m->position, 1)) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </details>
    </section>
    <script type="module" src="/assets/js/line-chart.js?v=<?= $view->e($assetVersion ?? '0') ?>"></script>
<?php endif; ?>

</div>
