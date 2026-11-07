<?php

declare(strict_types=1);

/** @var SeoControlCenter\Core\View\Template $view */
/** @var SeoControlCenter\Domain\Projects\Project $project */
/** @var list<SeoControlCenter\Modules\Pages\PageSummary> $rows */
/** @var SeoControlCenter\Domain\Shared\DateRange $range */
/** @var int $page */
/** @var int $total */
/** @var list<SeoControlCenter\Modules\Pages\PageExport> $exports */
/** @var int $directExportLimit */
/** @var int $maxExportUrls */
$base = '/projects/' . $project->publicId . '/pages';
$pageLink = static fn(int $number): string => $base . '?' . http_build_query(['q' => $search, 'sort' => $sort, 'per_page' => $perPage, 'page' => $number]);
$view->extend('layouts/app', ['title' => 'Pages · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'pages']);
?>
<div class="research-workspace research-pages">
<header class="research-intro"><div><p class="portfolio-eyebrow">Research · Pages</p><h2>Page library</h2><p class="muted">Explore search traffic, audit evidence and overlapping queries for every known URL.</p></div><nav class="research-shortcuts" aria-label="Research sections"><a href="#research-page-library">Page library</a><a href="#research-page-overlap">Query overlap</a></nav></header>
<section class="card" id="research-page-library">
    <div class="card-header"><h2>Every known URL</h2><span class="badge badge-info"><?= $view->e(number_format($total)) ?> <?= $search !== '' ? 'matching' : 'known' ?> URLs</span></div>
    <p class="muted">Search traffic, rankings, audit issues and AI citations together. Search traffic covers <?= $view->e($range->fromString()) ?> to <?= $view->e($range->toString()) ?>. Bing uses weekly buckets ending in this range. A dash means no imported data.</p>
    <form method="get" action="<?= $view->e($base) ?>" class="table-toolbar">
        <div class="table-tool-field"><label for="pages-search">Search all known URLs</label><input type="search" id="pages-search" name="q" maxlength="200" value="<?= $view->e($search) ?>" placeholder="URL or path…"></div>
        <div class="table-tool-field"><label for="pages-sort">Sort all results</label><select id="pages-sort" name="sort"><?php foreach (['url' => 'URL A–Z', 'google_clicks' => 'Google clicks · highest first', 'bing_clicks' => 'Bing clicks · highest first', 'impressions' => 'Google impressions · highest first'] as $key => $label): ?><option value="<?= $view->e($key) ?>"<?= $sort === $key ? ' selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?></select></div>
        <div class="table-tool-field"><label for="pages-size">URLs per page</label><select id="pages-size" name="per_page"><?php foreach ([25, 50, 100] as $size): ?><option value="<?= $size ?>"<?= $perPage === $size ? ' selected' : '' ?>><?= $size ?></option><?php endforeach; ?></select></div>
        <button type="submit" class="button button-small">Apply filters</button><a href="<?= $view->e($base) ?>">Reset</a>
    </form>
    <?php if ($total <= $directExportLimit): ?>
    <p><a class="button button-small button-ghost" href="<?= $view->e($base . '?' . http_build_query(['q' => $search, 'sort' => $sort, 'format' => 'csv', 'scope' => 'all'])) ?>">Export all matching URLs</a> <span class="muted small">Downloads every matching URL in the selected sort order.</span></p>
    <?php elseif ($total <= $maxExportUrls): ?>
    <form method="post" action="<?= $view->e($base . '/exports') ?>"><?= $view->csrfField() ?><input type="hidden" name="q" value="<?= $view->e($search) ?>"><input type="hidden" name="sort" value="<?= $view->e($sort) ?>"><p><button type="submit" class="button button-small">Prepare CSV export of <?= $view->e(number_format($total)) ?> URLs</button> <span class="muted small">More than <?= $view->e(number_format($directExportLimit)) ?> URLs are prepared by background tasks in the selected sort order. Download the file under Prepared exports when it is ready; it is kept for 24 hours.</span></p></form>
    <?php else: ?>
    <p class="muted small">CSV export supports up to <?= $view->e(number_format($maxExportUrls)) ?> matching URLs. Narrow the URL search to export these results.</p>
    <?php endif; ?>
    <p><a class="button button-small button-ghost" href="<?= $view->e($base . '?' . http_build_query(['q' => $search, 'sort' => $sort, 'per_page' => $perPage, 'page' => $page, 'format' => 'csv'])) ?>">Export this page as CSV</a> <span class="muted small">Exports the current page in its displayed order. Empty metric cells mean no imported data. Bing uses weekly buckets ending in the shown period.</span></p>
    <p><?= $view->e(number_format($total)) ?> <?= $search !== '' ? 'matching' : 'known' ?> <?= $total === 1 ? 'URL' : 'URLs' ?> from search imports, rankings, AI citations, backlink samples, GA4 and the latest completed crawl.</p>
    <div class="table-scroll"><table class="table table-dense" data-no-table-controls>
        <thead><tr><th scope="col">URL</th><th scope="col">Google clicks</th><th scope="col">Bing clicks</th><th scope="col">Google impressions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr><td><a href="<?= $view->e($base . '/details?url=' . rawurlencode($row->url)) ?>"><?= $view->e($row->url) ?></a></td>
                <td><?= $row->google !== null ? $view->e(number_format($row->google->clicks)) : '—' ?></td>
                <td><?= $row->bing !== null ? $view->e(number_format($row->bing->clicks)) : '—' ?></td>
                <td><?= $row->google !== null ? $view->e(number_format($row->google->impressions)) : '—' ?></td></tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?><tr><td colspan="4"><?= $search !== '' ? 'No URLs match this search. Change or reset the filters.' : 'No URLs yet. Import search data or run a site audit to discover pages.' ?></td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <nav class="pagination" aria-label="Pages pagination">
        <?php if ($page > 1): ?><a class="button button-small button-ghost" href="<?= $view->e($pageLink($page - 1)) ?>">Previous</a><?php endif; ?>
        <span>Page <?= $view->e($page) ?> of <?= $view->e($pageCount) ?> · <?= $view->e(number_format($total)) ?> URLs</span>
        <?php if ($page < $pageCount): ?><a class="button button-small button-ghost" href="<?= $view->e($pageLink($page + 1)) ?>">Next</a><?php endif; ?>
    </nav>
</section>
<?php if ($exports !== []): ?>
<section class="card" id="page-exports" aria-labelledby="page-exports-heading">
    <div class="card-header"><h2 id="page-exports-heading">Prepared exports</h2></div>
    <p class="muted small">Large exports are written by background tasks and kept for 24 hours. Rows reflect stored data for the period shown.</p>
    <div class="table-scroll"><table class="table table-dense" data-no-table-controls>
        <thead><tr><th scope="col">Requested (UTC)</th><th scope="col">Search</th><th scope="col">Sort</th><th scope="col">Period</th><th scope="col">Status</th><th scope="col">File</th></tr></thead>
        <tbody>
        <?php foreach ($exports as $export): ?>
            <tr>
                <td><?= $view->e($export->createdAt) ?></td>
                <td><?= $export->search !== '' ? $view->e($export->search) : '<span class="muted">All URLs</span>' ?></td>
                <td><?= $view->e($export->sortLabel()) ?></td>
                <td><?= $view->e($export->rangeFrom . ' – ' . $export->rangeTo) ?></td>
                <td><?php if ($export->status === SeoControlCenter\Modules\Pages\PageExport::READY): ?><span class="badge badge-ok">Ready</span> <?= $view->e(number_format($export->rowCount)) ?> URLs<?php elseif ($export->status === SeoControlCenter\Modules\Pages\PageExport::FAILED): ?><span class="badge badge-error">Failed</span> <?= $view->e((string) $export->error) ?><?php else: ?><span class="badge badge-info">Preparing</span> <?= $view->e(number_format($export->rowCount)) ?> of <?= $view->e(number_format($export->totalUrls)) ?> URLs<?php endif; ?></td>
                <td><?php if ($export->status === SeoControlCenter\Modules\Pages\PageExport::READY): ?><a href="<?= $view->e($base . '/exports/' . $export->publicId) ?>">Download CSV</a> <span class="muted small">until <?= $view->e((string) $export->expiresAt) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>
<section class="card" id="research-page-overlap">
    <h2>Possible keyword cannibalization</h2>
    <p class="muted">Queries with at least 200 impressions and several pages in the top 20. Each page must have at least 50 impressions and 10% of the query's impressions. Check search intent before merging or redirecting pages; several relevant results can be useful.</p>
    <?php if ($queryPageSpan === null): ?><p>Joint query/page data has not been imported yet. The next Search Console import backfills the latest 28 days.</p><?php endif; ?>
    <?php if ($analysisLimited): ?><p role="status">Analysis uses the 5,000 query/page pairs with the most impressions. Other pairs may contain additional candidates.</p><?php endif; ?>
    <?php foreach ($cannibalization as $group): ?>
        <h3><?= $view->e($group['query']) ?></h3>
        <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Competing URL</th><th scope="col">Clicks</th><th scope="col">Impressions</th><th scope="col">Average position</th><th scope="col">Impression share</th></tr></thead><tbody>
        <?php foreach ($group['pages'] as $candidate): ?>
            <tr><td><a href="<?= $view->e($base . '/details?url=' . rawurlencode($candidate['url'])) ?>"><?= $view->e($candidate['url']) ?></a></td><td><?= $view->e($candidate['clicks']) ?></td><td><?= $view->e($candidate['impressions']) ?></td><td><?= $view->e(number_format($candidate['position'], 1)) ?></td><td><?= $view->e(number_format($candidate['share'] * 100, 1)) ?>%</td></tr>
        <?php endforeach; ?></tbody></table></div>
    <?php endforeach; ?>
    <?php if ($queryPageSpan !== null && $cannibalization === []): ?><p>No candidates in the imported data.</p><?php endif; ?>
</section>

</div>
