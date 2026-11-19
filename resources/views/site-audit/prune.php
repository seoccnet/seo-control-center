<?php

declare(strict_types=1);

use SeoControlCenter\Modules\SiteAudit\Controllers\PageCleanupController;
use SeoControlCenter\Modules\SiteAudit\PrunePagesService;

/**
 * Pages to prune (1.7.0): indexable pages without any search impressions.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var bool $audited
 * @var bool $searchData
 * @var bool $enoughData
 * @var bool $partial
 * @var ?SeoControlCenter\Domain\Shared\DateRange $range
 * @var int $indexable
 * @var int $newPages
 * @var ?string $since
 * @var array<string, int> $counts
 * @var bool $linkSample
 * @var list<array{url: string, action: string, words: ?int, inbound: int, backlinks: int, title: ?string}> $rows
 */

$base = '/projects/' . $project->publicId . '/site-audit';
$view->extend('layouts/app', ['title' => 'Pages to prune · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [$base, 'Site audit'], [null, 'Pages to prune']]]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'site-audit']);
$num = static fn(int $n): string => number_format($n);
$candidates = array_sum($counts);
$advice = [
    PrunePagesService::MERGE => 'Short page: merge its useful part into a related page and redirect it there, or remove it (410).',
    PrunePagesService::IMPROVE => 'Rework it for a search people make, or set it to noindex if visitors still need it.',
    PrunePagesService::REDIRECT => 'Other sites link to it: keep it, or redirect it (301) to the closest page so the links still count.',
];
?>
<div class="improve-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow"><a href="<?= $view->e($base) ?>">Site audit</a></p><h2>Pages to prune</h2><p class="muted">Indexable pages Google did not show in a single search for up to a year. Many such pages make a site look thin and spend crawl time on pages nobody finds. Each page has a suggestion; nothing changes on your site until you act.</p></div></header>

<?php if (!$audited || !$searchData): ?>
<section class="card empty">
    <h2><?= !$audited ? 'Run a site audit first' : 'Connect Search Console first' ?></h2>
    <p>This report compares the pages of the site audit with the pages Google showed in Search Console. Both are needed.</p>
    <p><a class="button" href="<?= $view->e(!$audited ? $base : '/projects/' . $project->publicId . '/search-console') ?>"><?= !$audited ? 'Run a site audit' : 'Link Search Console' ?></a></p>
</section>
<?php elseif (!$enoughData): ?>
<section class="card empty">
    <h2>Not enough Search Console data yet</h2>
    <p>Only <?= $view->e((string) ($range?->days() ?? 0)) ?> days are imported. A page without searches says little until at least <?= $view->e((string) PrunePagesService::MIN_DAYS) ?> days are; the import fetches up to 16 months in the background.</p>
</section>
<?php else: ?>
<div class="stat-grid">
    <section class="stat"><h2 class="stat-label">Indexable pages</h2><p class="stat-value"><?= $view->e($num($indexable)) ?></p></section>
    <section class="stat"><h2 class="stat-label">No impressions</h2><p class="stat-value"><?= $view->e($num($candidates)) ?></p><?php if ($range !== null): ?><p class="stat-note"><?= $view->e($range->fromString() . ' – ' . $range->toString()) ?></p><?php endif; ?></section>
    <?php foreach ([PrunePagesService::MERGE, PrunePagesService::IMPROVE, PrunePagesService::REDIRECT] as $action): ?>
    <section class="stat"><h2 class="stat-label"><?= $view->e(PageCleanupController::ACTIONS[$action]) ?></h2><p class="stat-value"><?= $view->e($num($counts[$action] ?? 0)) ?></p></section>
    <?php endforeach; ?>
</div>

<?php if ($partial): ?><p class="notice">Your site has more pages with impressions than SEOcc reads at once; some pages listed here may have a few.</p><?php endif; ?>

<section class="card" aria-labelledby="prune-heading">
    <div class="card-header"><h2 id="prune-heading">Pages without impressions</h2><?php if ($rows !== []): ?><a class="button button-ghost button-small" href="<?= $view->e($base . '/prune?format=csv') ?>"><?= $view->icon('download') ?>Export CSV</a><?php endif; ?></div>
    <?php if ($rows === []): ?>
    <p class="muted">Google showed every indexable page at least once.</p>
    <?php else: ?>
    <div class="table-scroll"><table class="table table-dense">
        <thead><tr><th scope="col">Page</th><th scope="col">Suggestion</th><th scope="col" class="numeric">Words</th><th scope="col" class="numeric">Internal links to it</th><th scope="col" class="numeric">Backlinks</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td class="key-cell"><a href="<?= $view->e($row['url']) ?>" rel="noopener noreferrer" target="_blank"><?= $view->e($row['url']) ?></a><?php if ($row['title'] !== null && $row['title'] !== ''): ?><br><span class="muted small"><?= $view->e($row['title']) ?></span><?php endif; ?></td>
                <td><strong><?= $view->e(PageCleanupController::ACTIONS[$row['action']]) ?></strong><br><span class="muted small"><?= $view->e($advice[$row['action']]) ?></span></td>
                <td class="numeric"><?= $row['words'] !== null ? $view->e($num($row['words'])) : '–' ?></td>
                <td class="numeric"><?= $view->e($num($row['inbound'])) ?></td>
                <td class="numeric"><?= $row['backlinks'] > 0 ? $view->e($num($row['backlinks'])) : '–' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if (count($rows) >= PrunePagesService::ROWS): ?><p class="muted small">The first <?= $view->e((string) PrunePagesService::ROWS) ?> are shown; the export has the same rows.</p><?php endif; ?>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Before you remove anything</h2>
    <ul>
        <li><?php if ($since !== null): ?><?= $view->e($num($newPages)) ?> page(s) that were not in the audit of <?= $view->e($since) ?> are left out as new.<?php else: ?>SEOcc has only one audit, so it cannot tell new pages apart: check the publishing date first.<?php endif; ?> New pages need time before Google shows them.</li>
        <li>Pages people reach in other ways (contact, legal, account, campaign landing pages) need no searches. Keep them; noindex is fine for them.</li>
        <li><?= $linkSample ? 'Backlinks come from the latest stored backlink sample; a page can have links the sample did not include.' : 'No backlink sample is stored, so backlinks are unknown. Fetch the backlinks on the Backlinks page first to see which pages have links.' ?></li>
        <li>Plan the redirects for removed and merged pages in <a href="<?= $view->e('/projects/' . $project->publicId . '/site-migration') ?>">Site migration</a>.</li>
    </ul>
</section>
<?php endif; ?>
</div>
