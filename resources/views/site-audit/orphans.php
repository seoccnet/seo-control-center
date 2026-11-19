<?php

declare(strict_types=1);

use SeoControlCenter\Modules\SiteAudit\OrphanPagesService;

/**
 * Orphan pages (1.7.0): pages Google shows that the site does not link to.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var bool $audited
 * @var bool $searchData
 * @var bool $complete
 * @var ?SeoControlCenter\Domain\Shared\DateRange $range
 * @var int $checked
 * @var array<string, int> $counts
 * @var int $sitemapNoTraffic
 * @var list<array{url: string, kind: string, clicks: int, impressions: int, position: float}> $rows
 */

$base = '/projects/' . $project->publicId . '/site-audit';
$view->extend('layouts/app', ['title' => 'Orphan pages · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [$base, 'Site audit'], [null, 'Orphan pages']]]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'site-audit']);
$num = static fn(int|float $n): string => number_format((float) $n);
$labels = [
    OrphanPagesService::SITEMAP_ONLY => 'Only in the sitemap',
    OrphanPagesService::NOT_REACHED => 'Not linked, not in the sitemap',
    OrphanPagesService::BEYOND_LIMIT => 'Not reached (page limit)',
];
$orphans = ($counts[OrphanPagesService::SITEMAP_ONLY] ?? 0) + ($counts[OrphanPagesService::NOT_REACHED] ?? 0);
?>
<div class="improve-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow"><a href="<?= $view->e($base) ?>">Site audit</a></p><h2>Orphan pages</h2><p class="muted">Pages Google shows in search that no page of your site links to. Visitors cannot click their way to them, and search engines treat pages without internal links as unimportant. They are often the cheapest pages to lift: link to them from a related page.</p></div></header>

<?php if (!$audited || !$searchData): ?>
<section class="card empty">
    <h2><?= !$audited ? 'Run a site audit first' : 'Connect Search Console first' ?></h2>
    <p>Orphan pages compare the pages Google shows (Search Console) with the links the site audit found. Both are needed.</p>
    <p><a class="button" href="<?= $view->e(!$audited ? $base : '/projects/' . $project->publicId . '/search-console') ?>"><?= !$audited ? 'Run a site audit' : 'Link Search Console' ?></a></p>
</section>
<?php else: ?>
<div class="stat-grid">
    <section class="stat"><h2 class="stat-label">Pages with impressions</h2><p class="stat-value"><?= $view->e($num($checked)) ?></p><?php if ($range !== null): ?><p class="stat-note"><?= $view->e($range->fromString() . ' – ' . $range->toString()) ?></p><?php endif; ?></section>
    <section class="stat"><h2 class="stat-label">Orphan pages</h2><p class="stat-value"><?= $view->e($num($orphans)) ?></p></section>
    <section class="stat"><h2 class="stat-label">Not reached by the audit</h2><p class="stat-value"><?= $view->e($num($counts[OrphanPagesService::BEYOND_LIMIT] ?? 0)) ?></p><p class="stat-note">page limit hit</p></section>
    <section class="stat"><h2 class="stat-label">Unlinked, no impressions</h2><p class="stat-value"><?= $view->e($num($sitemapNoTraffic)) ?></p><p class="stat-note">sitemap pages Google does not show</p></section>
</div>

<?php if (!$complete): ?>
<p class="notice">The latest audit stopped at its page limit, so some linked pages were never reached. Pages it did not reach are listed separately: they may be linked from pages beyond the limit. Raise the limit in the audit settings for a certain answer.</p>
<?php endif; ?>

<section class="card" aria-labelledby="orphans-heading">
    <h2 id="orphans-heading">Pages Google shows that your site does not link to</h2>
    <?php if ($rows === []): ?>
    <p class="muted">Every page Google showed in the last <?= $view->e((string) OrphanPagesService::DAYS) ?> days is linked from your site.</p>
    <?php else: ?>
    <div class="table-scroll"><table class="table table-dense numeric">
        <thead><tr><th scope="col">Page</th><th scope="col">Found</th><th scope="col">Clicks</th><th scope="col">Impressions</th><th scope="col">Position</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td class="key-cell"><a href="<?= $view->e($row['url']) ?>" rel="noopener noreferrer" target="_blank"><?= $view->e($row['url']) ?></a></td>
                <td><?= $view->e($labels[$row['kind']] ?? $row['kind']) ?></td>
                <td><?= $view->e($num($row['clicks'])) ?></td>
                <td><?= $view->e($num($row['impressions'])) ?></td>
                <td><?= $view->e(number_format($row['position'], 1)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if (count($rows) >= OrphanPagesService::ROWS): ?><p class="muted small">The first <?= $view->e((string) OrphanPagesService::ROWS) ?> are shown.</p><?php endif; ?>
    <?php endif; ?>
</section>

<section class="card">
    <h2>What to do</h2>
    <ul>
        <li><strong>Pages worth keeping:</strong> link to them from a related page that is linked itself, in the text or in a listing. <a href="<?= $view->e($base . '/links') ?>">Internal link suggestions</a> show pages on the same topic.</li>
        <li><strong>Old or replaced pages:</strong> redirect them to their replacement, so the searches they still get land on a current page.</li>
        <li><strong>Pages only linked from script menus or forms:</strong> the audit follows normal links only. Add a plain link as well.</li>
    </ul>
    <p class="muted small">Search Console reports a page under the address Google indexed. Addresses on other hosts than the ones the audit crawled are left out.</p>
</section>
<?php endif; ?>
</div>
