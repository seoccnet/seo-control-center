<?php

declare(strict_types=1);

use SeoControlCenter\Domain\Snippets\TitleRewrite;
use SeoControlCenter\Modules\SiteAudit\TitleRewriteService;

/**
 * Titles Google rewrites (1.7.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var ?string $kind
 * @var bool $audited
 * @var int $titles
 * @var int $pages
 * @var int $unaudited
 * @var array<string, int> $counts
 * @var list<array{url: string, own: ?string, shown: string, kind: string, overlap: float, keywords: list<string>, checkedOn: string}> $rows
 */

$base = '/projects/' . $project->publicId . '/site-audit';
$view->extend('layouts/app', ['title' => 'Titles Google rewrites · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [$base, 'Site audit'], [null, 'Titles Google rewrites']]]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'site-audit']);
$labels = [
    TitleRewrite::REWRITTEN => ['Rewritten', 'Google wrote a different title.'],
    TitleRewrite::MISSING => ['No title', 'The page has no title, so Google wrote one.'],
    TitleRewrite::SITE_NAME => ['Site name changed', 'Google kept your title but added, dropped or changed the site name part.'],
    TitleRewrite::CUT => ['Cut', 'Google showed the beginning and cut the rest.'],
];
$num = static fn(int $n): string => number_format($n);
?>
<div class="improve-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow"><a href="<?= $view->e($base) ?>">Site audit</a></p><h2>Titles Google rewrites</h2><p class="muted">The title Google showed for your pages in the rank checks of the last <?= $view->e((string) TitleRewriteService::DAYS) ?> days, next to the title the page has. Google rewrites titles it finds too long, repetitive, vague or unlike the page. A rewrite tells you how Google reads the page; it is not an error.</p></div></header>

<?php if ($titles === 0 && $unaudited === 0): ?>
<section class="card empty">
    <h2>No titles recorded yet</h2>
    <p>Rank checks record the title Google shows for your pages from version 1.7.0 on. They appear here after the next check of keywords you rank for<?= $audited ? '' : ', together with a completed site audit' ?>.</p>
    <p><a class="button button-secondary" href="<?= $view->e('/projects/' . $project->publicId . '/rankings') ?>">Rankings</a><?php if (!$audited): ?> <a class="button button-secondary" href="<?= $view->e($base) ?>">Run a site audit</a><?php endif; ?></p>
</section>
<?php elseif (!$audited): ?>
<section class="card empty">
    <h2>Run a site audit first</h2>
    <p>Google's titles are recorded for <?= $view->e($num($unaudited)) ?> page(s). A completed site audit reads your own titles to compare them with.</p>
    <p><a class="button" href="<?= $view->e($base) ?>">Run a site audit</a></p>
</section>
<?php else: ?>
<div class="stat-grid">
    <section class="stat"><h2 class="stat-label">Titles compared</h2><p class="stat-value"><?= $view->e($num($titles)) ?></p><p class="stat-note">on <?= $view->e($num($pages)) ?> page(s)</p></section>
    <section class="stat"><h2 class="stat-label">Shown as written</h2><p class="stat-value"><?= $view->e($num($counts[TitleRewrite::KEPT] ?? 0)) ?></p></section>
    <section class="stat"><h2 class="stat-label">Rewritten</h2><p class="stat-value"><?= $view->e($num(($counts[TitleRewrite::REWRITTEN] ?? 0) + ($counts[TitleRewrite::MISSING] ?? 0))) ?></p></section>
    <section class="stat"><h2 class="stat-label">Site name or cut</h2><p class="stat-value"><?= $view->e($num(($counts[TitleRewrite::SITE_NAME] ?? 0) + ($counts[TitleRewrite::CUT] ?? 0))) ?></p></section>
</div>

<section class="card" aria-labelledby="rewrites-heading">
    <div class="card-header"><h2 id="rewrites-heading">Titles Google changed</h2>
        <nav class="segmented" aria-label="Kind of change">
            <a href="<?= $view->e($base . '/title-rewrites') ?>"<?= $kind === null ? ' aria-current="true"' : '' ?>>All</a>
            <?php foreach ($labels as $key => [$label]): ?>
            <a href="<?= $view->e($base . '/title-rewrites?kind=' . $key) ?>"<?= $kind === $key ? ' aria-current="true"' : '' ?>><?= $view->e($label) ?> (<?= $view->e($num($counts[$key] ?? 0)) ?>)</a>
            <?php endforeach; ?>
        </nav>
    </div>
    <?php if ($rows === []): ?>
    <p class="muted"><?= $kind === null ? 'Google showed every compared title as written.' : 'No titles of this kind.' ?></p>
    <?php else: ?>
    <div class="table-scroll"><table class="table table-dense">
        <thead><tr><th scope="col">Page</th><th scope="col">Your title</th><th scope="col">Google showed</th><th scope="col">Change</th><th scope="col">For searches</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td class="key-cell"><a href="<?= $view->e($row['url']) ?>" rel="noopener noreferrer" target="_blank"><?= $view->e((string) (parse_url($row['url'], PHP_URL_PATH) ?: '/')) ?></a></td>
                <td><?= $row['own'] !== null && trim($row['own']) !== '' ? $view->e($row['own']) : '<span class="muted">none</span>' ?></td>
                <td><?= $view->e($row['shown']) ?></td>
                <td><?= $view->e($labels[$row['kind']][0] ?? $row['kind']) ?><?php if ($row['kind'] === TitleRewrite::REWRITTEN): ?><br><span class="muted small"><?= $view->e((string) (int) round($row['overlap'] * 100)) ?> % of its words are yours</span><?php endif; ?></td>
                <td class="small"><?= $view->e(implode(', ', $row['keywords'])) ?><br><span class="muted">checked <?= $view->e($row['checkedOn']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if (count($rows) >= TitleRewriteService::ROWS): ?><p class="muted small">The first <?= $view->e((string) TitleRewriteService::ROWS) ?> are shown.</p><?php endif; ?>
    <?php endif; ?>
    <?php if ($unaudited > 0): ?><p class="muted small"><?= $view->e($num($unaudited)) ?> ranking page(s) were not in the latest audit and are left out.</p><?php endif; ?>
</section>

<section class="card">
    <h2>What to do</h2>
    <ul>
        <li><strong>Rewritten:</strong> compare Google's wording with yours. When Google uses the page's main heading or a phrase from the page, your title probably does not match what the page is about, or repeats keywords. Write a title close to the main heading.</li>
        <li><strong>No title:</strong> add one. Google builds titles from headings and links when the page has none.</li>
        <li><strong>Site name changed:</strong> usually harmless. Google takes the site name from your structured data and home page; set it consistently there.</li>
        <li><strong>Cut:</strong> the title is too wide; shorten it in the <a href="<?= $view->e($base . '/snippets?cut=1') ?>">snippet preview</a>.</li>
    </ul>
    <p class="muted small">Google may show a different title for different searches, so a page can appear more than once. Titles come from your rank checks on the tracked device only.</p>
</section>
<?php endif; ?>
</div>
