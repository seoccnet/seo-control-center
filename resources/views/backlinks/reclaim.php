<?php

declare(strict_types=1);

/**
 * Backlinks pointing to missing pages, with a suggested redirect (1.5.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var array{state: string, error: ?string, queued_at: string, finished_at: ?string}|null $run
 * @var string|null $linksFetchedAt
 * @var int $targets
 * @var int $checked
 * @var int $unreachable
 * @var list<array{url: string, problem: string, links: int, domains: int, dofollow: int, bestRank: ?int, sources: list<SeoControlCenter\Domain\Backlinks\PageBacklink>, suggestion: ?array{url: string, method: string, confidence: int}}> $dead
 * @var bool $canRun
 * @var bool $available
 */

$base = '/projects/' . $project->publicId . '/backlinks';
$view->extend('layouts/app', ['title' => 'Links to missing pages · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [$base, 'Backlinks'], [null, 'Links to missing pages']]]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'backlinks']);
$busy = in_array($run['state'] ?? null, ['queued', 'running'], true);
$methods = ['path' => 'same address', 'slug' => 'same page name', 'similar' => 'similar words', 'parent' => 'parent folder'];
?>
<div class="improve-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow"><a href="<?= $view->e($base) ?>">Backlinks</a></p><h2>Links to missing pages</h2><p class="muted">Other sites still link to these pages, but they no longer exist. A 301 redirect to the closest live page lets those links count again.</p></div></header>

<section class="card" aria-labelledby="reclaim-status-heading">
    <h2 id="reclaim-status-heading">Check</h2>
    <?php if ($targets === 0): ?>
    <p>No stored links to <?= $view->e($project->primaryDomain) ?> yet. Fetch <a href="<?= $view->e($base . '#bl_links') ?>">Live links by page</a> first (a paid DataForSEO lookup); the linked pages are then checked automatically.</p>
    <?php else: ?>
    <p><?= $view->e(number_format($checked)) ?> of <?= $view->e(number_format($targets)) ?> linked pages checked<?= $linksFetchedAt !== null ? ' · links fetched ' . $view->e(substr($linksFetchedAt, 0, 10)) : '' ?><?= ($run['finished_at'] ?? null) !== null ? ' · last check ' . $view->e(substr((string) $run['finished_at'], 0, 16)) . ' UTC' : '' ?><?= $unreachable > 0 ? ' · ' . $view->e(number_format($unreachable)) . ' could not be checked (robots.txt or no answer)' : '' ?></p>
    <?php if ($busy): ?><p role="status">The linked pages are being checked in the background. This page shows the results so far.</p><?php endif; ?>
    <?php if (($run['state'] ?? null) === 'failed' && $run['error'] !== null): ?><p class="notice notice-warning"><?= $view->e($run['error']) ?></p><?php endif; ?>
    <?php if ($canRun && $available && !$busy): ?>
    <form method="post" action="<?= $view->e($base . '/reclaim') ?>"><?= $view->csrfField() ?><button class="button button-small">Check the linked pages again</button></form>
    <p class="muted small">One request per page to your own site, no provider cost. Robots.txt is respected.</p>
    <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($targets > 0): ?>
<section class="card" aria-labelledby="reclaim-heading">
    <h2 id="reclaim-heading">Missing pages with backlinks</h2>
    <?php if ($dead === []): ?>
    <p><?= $checked > 0 ? 'Every checked page that other sites link to answers. Nothing to reclaim.' : 'No results yet.' ?></p>
    <?php else: ?>
    <div class="table-scroll"><table class="table table-dense">
        <thead><tr><th scope="col">Missing page</th><th scope="col">Answer</th><th scope="col">Linking sites</th><th scope="col">Links (follow)</th><th scope="col">Best rank</th><th scope="col">Strongest links</th><th scope="col">Suggested redirect</th></tr></thead>
        <tbody>
        <?php foreach ($dead as $row): ?>
            <tr>
                <td class="url-cell"><?= $view->e($row['url']) ?></td>
                <td><?= $view->e($row['problem']) ?></td>
                <td><?= $view->e((string) $row['domains']) ?></td>
                <td><?= $view->e($row['links'] . ' (' . $row['dofollow'] . ')') ?></td>
                <td><?= $view->e($row['bestRank'] !== null ? (string) $row['bestRank'] : '—') ?></td>
                <td class="url-cell small"><?php foreach ($row['sources'] as $source): ?><div><?= $view->e($source->sourceUrl) ?><?= $source->anchor !== '' ? ' · “' . $view->e(mb_strimwidth($source->anchor, 0, 60, '…')) . '”' : '' ?></div><?php endforeach; ?></td>
                <td class="url-cell"><?php if ($row['suggestion'] !== null): ?><?= $view->e($row['suggestion']['url']) ?><div class="muted small"><?= $view->e(($methods[$row['suggestion']['method']] ?? $row['suggestion']['method']) . ' · ' . $row['suggestion']['confidence'] . '% match') ?></div><?php else: ?><span class="muted">No close live page. Restore the page or pick a target by hand.</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="muted small">Suggestions come from the live linked pages and the pages with Search Console data. Check each target covers the same topic: redirecting everything to the home page is treated as a soft 404.</p>
    <?php endif; ?>
</section>
<?php endif; ?>
</div>
