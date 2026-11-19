<?php

declare(strict_types=1);

use SeoControlCenter\Modules\SiteAudit\LinkStrengthService;

/**
 * Internal link strength (1.8.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var bool $audited
 * @var bool $searchData
 * @var ?SeoControlCenter\Domain\Shared\DateRange $range
 * @var int $pages
 * @var int $links
 * @var string $show
 * @var array<string, int> $counts
 * @var list<array{url: string, strength: int, inbound: int, depth: ?int, clicks: ?int, impressions: ?int, indexable: bool, reason: ?string}> $rows
 */

$base = '/projects/' . $project->publicId . '/site-audit';
$view->extend('layouts/app', ['title' => 'Internal link strength · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [$base, 'Site audit'], [null, 'Internal link strength']]]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'site-audit']);
$tabs = [
    LinkStrengthService::UNDERLINKED => 'Earn clicks, weakly linked',
    LinkStrengthService::NO_SEARCHES => 'Strongly linked, no searches',
    LinkStrengthService::ALL => 'All pages',
];
$path = static function (string $url): string {
    $p = (string) parse_url($url, PHP_URL_PATH);
    $q = parse_url($url, PHP_URL_QUERY);

    return ($p !== '' ? $p : '/') . (is_string($q) ? '?' . $q : '');
};
$num = static fn(?int $n): string => $n === null ? '–' : number_format($n);
?>
<div class="improve-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow"><a href="<?= $view->e($base) ?>">Site audit</a></p><h2>Internal link strength</h2><p class="muted">How much of your site's internal linking each page receives: links from strongly linked pages count more than links from weak ones, as in Google's original PageRank. 100 means stronger than every other page, 50 stronger than half of them. Next to it: the page's Search Console clicks of the last <?= $view->e((string) LinkStrengthService::DAYS) ?> days.</p></div></header>

<?php if (!$audited): ?>
<section class="card empty">
    <h2>Run a site audit first</h2>
    <p>Link strength is calculated from the links the site audit found between your pages.</p>
    <p><a class="button" href="<?= $view->e($base) ?>">Run a site audit</a></p>
</section>
<?php else: ?>
<div class="stat-grid">
    <section class="stat"><h2 class="stat-label">Pages</h2><p class="stat-value"><?= $view->e(number_format($pages)) ?></p><p class="stat-note">from the latest audit</p></section>
    <section class="stat"><h2 class="stat-label">Internal links</h2><p class="stat-value"><?= $view->e(number_format($links)) ?></p></section>
    <section class="stat"><h2 class="stat-label">Earn clicks, weakly linked</h2><p class="stat-value"><?= $searchData ? $view->e(number_format($counts[LinkStrengthService::UNDERLINKED])) : '–' ?></p></section>
    <section class="stat"><h2 class="stat-label">Strongly linked, no searches</h2><p class="stat-value"><?= $searchData ? $view->e(number_format($counts[LinkStrengthService::NO_SEARCHES])) : '–' ?></p></section>
</div>
<?php if (!$searchData): ?>
<p class="notice">Link Search Console to compare link strength with clicks. Until then only the strength of each page is shown.</p>
<?php endif; ?>

<section class="card" aria-labelledby="strength-heading">
    <h2 id="strength-heading"><?= $view->e($tabs[$show]) ?></h2>
    <nav class="filter-row" aria-label="Lists">
        <?php foreach ($tabs as $key => $label): ?>
            <a class="chip" href="<?= $view->e($base . '/link-strength?show=' . $key) ?>"<?= $show === $key ? ' aria-current="true"' : '' ?>><?= $view->e($label) ?> <span class="muted"><?= $view->e(number_format($counts[$key])) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($show === LinkStrengthService::UNDERLINKED): ?>
    <p class="muted small">Pages among your top quarter by clicks (at least <?= $view->e((string) LinkStrengthService::MIN_CLICKS) ?>) that are weaker than half your pages or more than <?= $view->e((string) LinkStrengthService::DEEP) ?> clicks from the start page. They already earn traffic; links from strong, related pages (menus, hubs, popular articles) usually lift them further.</p>
    <?php elseif ($show === LinkStrengthService::NO_SEARCHES): ?>
    <p class="muted small">Pages in your strongest quarter that Google showed for nothing in the period. Usually menus or footers link to them on every page. Fine for contact, legal or account pages; otherwise move links from them to pages that should rank.</p>
    <?php endif; ?>
    <?php if ($rows === []): ?>
    <p class="muted">No pages in this list.</p>
    <?php else: ?>
    <div class="table-scroll"><table class="table table-dense">
        <thead><tr><th scope="col">Page</th><th scope="col" class="numeric">Strength</th><th scope="col">Compared with other pages</th><th scope="col" class="numeric">Linked from</th><th scope="col" class="numeric">Clicks from start</th><th scope="col" class="numeric">Clicks</th><th scope="col" class="numeric">Impressions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td class="small"><a href="<?= $view->e($row['url']) ?>" rel="noopener noreferrer" target="_blank"><?= $view->e($path($row['url'])) ?></a><?= !$row['indexable'] ? ' <span class="badge">Not indexable</span>' : '' ?></td>
                <td class="numeric"><?= $view->e((string) $row['strength']) ?></td>
                <td><meter min="0" max="100" value="<?= $view->e((string) $row['strength']) ?>" aria-label="Strength <?= $view->e((string) $row['strength']) ?> of 100"></meter></td>
                <td class="numeric"><?= $view->e(number_format($row['inbound'])) ?></td>
                <td class="numeric"><?= $row['depth'] === null ? '<span class="muted">not linked</span>' : $view->e((string) $row['depth']) ?></td>
                <td class="numeric"><?= $view->e($num($row['clicks'])) ?></td>
                <td class="numeric"><?= $view->e($num($row['impressions'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if (count($rows) >= LinkStrengthService::ROWS): ?><p class="muted small">The first <?= $view->e(number_format(LinkStrengthService::ROWS)) ?> pages are shown.</p><?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>
</div>
